<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category  Nosto
 * @package   Nosto_Tagging
 * @author    Nosto Solutions Ltd <magento@nosto.com>
 * @copyright Copyright (c) 2013-2017 Nosto Solutions Ltd (http://www.nosto.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Handles sending product updates to Nosto via the API.
 *
 */
class Nosto_Tagging_Model_Service_Product
{
    /**
     * Maximum batch size. If exceeded the batches will be splitted into smaller
     * ones.
     *
     * @var int
     */
    public static $maxBatchSize = 500;

    /**
     * Max time to wait for Nosto's API response
     *
     * @var int
     */
    public static $apiWaitTimeout = 60;

    /**
     * Maximum amount of products that will be processed and sent to Nosto
     *
     * @var int
     */
    public static $hardLimitMaxProcuts = 100000;

    /**
     * Sends a product update to Nosto for all stores and installed Nosto
     * accounts
     *
     * @param Mage_Catalog_Model_Product[] $products
     * @return boolean
     */
    protected function update(array $products)
    {
        $productsInStore = array();
        $counter = 0;
        $batch = 1;
        foreach ($products as $product) {
            if ($counter > 0 && $counter % self::$maxBatchSize === 0) {
                ++$batch;
            }
            ++$counter;
            if ($product instanceof Mage_Catalog_Model_Product === false) {
                Mage::throwException(
                    sprintf(
                        'Invalid data type, expecting Mage_Catalog_Model_Product' .
                        ', got %s',
                        get_class($product)
                    )
                );
            }

            $productsToUpdate = Nosto_Tagging_Util_Product::toParentProducts($product);
            foreach ($productsToUpdate as $productToUpdate) {
                foreach ($product->getStoreIds() as $storeId) {
                    if (!isset($productsInStore[$storeId])) {
                        $productsInStore[$storeId] = array();
                    }
                    if (!isset($productsInStore[$storeId][$batch])) {
                        $productsInStore[$storeId][$batch] = array();
                    }
                    $productsInStore[$storeId][$batch][] = $productToUpdate;
                }
            }
        }
        // Batch ready - process batches for each store
        foreach ($productsInStore as $storeId => $productBatches) {
            $store = Mage::app()->getStore($storeId);
            /** @var Nosto_Tagging_Helper_Account $helper */
            $helper = Mage::helper('nosto_tagging/account');
            $account = $helper->find($store);
            /* @var $nostoHelper Nosto_Tagging_Helper_Data */
            $nostoHelper = Mage::helper('nosto_tagging');
            if (
                $account === null
                || !$account->isConnectedToNosto()
                || !$nostoHelper->getUseProductApi($store)
            ) {
                continue;
            }
            /* @var Mage_Core_Model_App_Emulation $emulation */
            $emulation = Mage::getSingleton('core/app_emulation');
            $env = $emulation->startEnvironmentEmulation($store->getId());
            foreach ($productBatches as $productsInStore) {
                try {
                    $operation = new Nosto_Operation_UpsertProduct($account);
                    $operation->setResponseTimeout(self::$apiWaitTimeout);
                    /* @var $mageProduct Mage_Catalog_Model_Product */
                    foreach ($productsInStore as $mageProduct) {
                        if ($mageProduct instanceof Mage_Catalog_Model_Product === false) {
                            continue;
                        }
                        /** @var Nosto_Tagging_Model_Meta_Product $nostoProduct */
                        $nostoProduct = Mage::getModel('nosto_tagging/meta_product');
                        // If the current store scope is the main store scope, also referred to as
                        // the admin store scope, then we should reload the product as the store
                        // code of the product refers to an pseudo store scope called "admin"
                        // which leads to issues when flat tables are enabled.
                        if ($nostoProduct->reloadData($mageProduct, $store)) {
                            $operation->addProduct($nostoProduct);
                        }
                    }
                    $operation->upsert();
                } catch (Exception $e) {
                    Nosto_Tagging_Helper_Log::exception($e);
                }
            }
            $emulation->stopEnvironmentEmulation($env);
        }

        return true;
    }

    /**
     * Updates a batch of products to Nosto
     *
     * @param Nosto_Tagging_Model_Resource_Product_Collection $products
     * @return boolean
     */
    public function updateBatch(Nosto_Tagging_Model_Resource_Product_Collection $products)
    {
        $productsArray = iterator_to_array($products);
        return $this->update($productsArray);
    }

    /**
     * Updates single product to Nosto
     *
     * @param Mage_Catalog_Model_Product $product
     * @return boolean
     */
    public function updateProduct(Mage_Catalog_Model_Product $product)
    {
        return $this->update(array($product));
    }

    /**
     * Send out of sync products from Nosto index to Nosto via API
     */
    public function updateOutOfSyncToNosto()
    {
        $storesWithNosto = Mage::helper('nosto_tagging/account')->getAllStoreViewsWithNostoAccount();
        foreach ($storesWithNosto as $store) {

            /** @var Nosto_Tagging_Helper_Account $helper */
            $helper = Mage::helper('nosto_tagging/account');
            $account = $helper->find($store);
            $operation = new Nosto_Operation_UpsertProduct($account);
            $operation->setResponseTimeout(self::$apiWaitTimeout);

            // loop through stores that have nosto & add store as filter
            $indexedProducts = Mage::getModel('nosto_tagging/index')
                ->getCollection()
                ->addFieldToFilter('in_sync', 0)
                ->addFieldToFilter('store_id', $store->getId())
                ->setPageSize(self::$hardLimitMaxProcuts); // @codingStandardsIgnoreLine
            $batchCounter = 0;
            $totalCounter = 0;
            $totalCount = count($indexedProducts);
            /* @var Nosto_Tagging_Model_Index $indexedProduct */
            foreach ($indexedProducts as $indexedProduct) {
                ++$batchCounter;
                ++$totalCounter;
                $nostoProduct = unserialize($indexedProduct->getNostoProduct());
                if ($nostoProduct instanceof Nosto_Tagging_Model_Meta_Product) {
                    $operation->addProduct($nostoProduct);
                }
                $indexedProduct->setInSync(1);
                $indexedProduct->save();
                if ($batchCounter % self::$maxBatchSize == 0
                    || $totalCounter == $totalCount
                ) {
                    try {
                        $operation->upsert();
                    } catch (\Exception $e) {
                        Nosto_Tagging_Helper_Log::exception($e);
                    }
                    $batchCounter = 0;
                    $operation = new Nosto_Operation_UpsertProduct($account);
                    $operation->setResponseTimeout(self::$apiWaitTimeout);
                }
            }
        }
    }

    /**
     * Send single product from Nosto index to Nosto via API
     *
     * @param Nosto_Tagging_Model_Index $nostoIndexedProduct
     * @param Mage_Core_Model_Store $store
     * @throws Exception
     */
    public function syncIndexedProduct(
        Nosto_Tagging_Model_Index $nostoIndexedProduct,
        Mage_Core_Model_Store $store
    )
    {
        /** @var Nosto_Tagging_Helper_Account $helper */
        $helper = Mage::helper('nosto_tagging/account');
        $account = $helper->find($store);
        $nostoProduct = unserialize($nostoIndexedProduct->getNostoProduct());
        if (
            $account instanceof Nosto_Object_Signup_Account
            && $nostoProduct instanceof Nosto_Tagging_Model_Meta_Product
        ) {
            $operation = new Nosto_Operation_UpsertProduct($account);
            $operation->setResponseTimeout(self::$apiWaitTimeout);
            $operation->addProduct($nostoProduct);
            $nostoIndexedProduct->setInSync(1);
            $nostoIndexedProduct->save();
            try {
                $operation->upsert();
            } catch (\Exception $e) {
                Nosto_Tagging_Helper_Log::exception($e);
            }
        }
    }
}
