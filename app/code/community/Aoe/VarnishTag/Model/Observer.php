<?php

class Aoe_VarnishTag_Model_Observer
{
    /**
     * log to /tmp/mage.log (needed for some special debugging purposes, therefore no Mage::log ;-) )
     *
     * @param mixed $data
     */
    protected function _log($data)
    {
        file_put_contents('/tmp/mage.log', print_r($data, 1) . "\n", FILE_APPEND);
    }

    /**
     * send a purge to varnish for a given tag
     *
     * @param string $tag
     */
    public function purge($tag)
    {
        $purgetag = "PURGE / HTTP/1.1
Host: localhost
X-Invalidates: " . $tag . "

";
        $this->_log($purgetag);
        $fp = fsockopen('localhost', 6081);
        fputs($fp, $purgetag);
        $this->_log(fgets($fp, 1024));
        fclose($fp);
    }

    /**
     * purge tags when entities are saved
     *
     * @param Varien_Event_Observer $observer
     */
    public function modelSaveAfter(Varien_Event_Observer $observer)
    {
        if ($observer->getObject() instanceof Mage_Cms_Model_Block) {
            $this->purge('cms-block-' . $observer->getObject()->getId());
        } else if ($observer->getObject() instanceof Mage_Cms_Model_Page) {
            $this->purge("cms-page-" . $observer->getObject()->getId());
        } else if ($observer->getObject() instanceof Mage_Catalog_Model_Category) {
            $this->purge("catalog-category-" . $observer->getObject()->getId());
        } else if ($observer->getObject() instanceof Mage_Catalog_Model_Product) {
            $this->purge("catalog-product-" . $observer->getObject()->getId());
        }
    }

    /**
     * collect tags passed to varnish
     *
     * @param Varien_Event_Observer $observer
     */
    public function coreBlockAbstractToHtmlBefore(Varien_Event_Observer $observer)
    {
        $block = $observer->getBlock();

        if ($block instanceof Mage_Cms_Block_Block) {
            $this->addTag('cms-block-' . $block->getBlock()->getId());
        } else if ($block instanceof Mage_Cms_Block_Page) {
            $this->addTag('cms-page-' . ($block->getPageId() ?: ($block->getPage() ? $block->getPage()->getId() : Mage::getSingleton('cms/page')->getId())));
        } else if (($block instanceof Mage_Catalog_Block_Product_Abstract) && $block->getProductCollection()) {
            $tags = array();
            foreach ($block->getProductCollection()->getAllIds() as $id) {
                $tags[] = 'catalog-product-' . $id;
            }
            $this->addTag($tags);
        }
    }

    /**
     * get current category layer
     *
     * @return Mage_Catalog_Model_Layer
     */
    public function getLayer()
    {
        $layer = Mage::registry('current_layer');
        if ($layer) {
            return $layer;
        }
        return Mage::getSingleton('catalog/layer');
    }

    /**
     * collect tags and add them as a X-Invalidates-By header stored in varnish to allow tag-based purging
     *
     * @param Varien_Event_Observer $observer
     */
    public function httpResponseSendBefore(Varien_Event_Observer $observer)
    {
        if (Mage::registry('product')) {
            $this->addTag('catalog-product-' . Mage::registry('product')->getId());
        }
        if ($layer = $this->getLayer()) {
            /** @var Mage_Catalog_Model_Layer $layer */
            $ids = $layer->getProductCollection()->getAllIds();
            $tags = array();
            foreach ($ids as $id) {
                $tags[] = 'catalog-product-' . $id;
            }
            $this->addTag($tags);
        }
        if (Mage::registry('current_category')) {
            /** @var Mage_Catalog_Model_Category $currentCategory */
            $currentCategory = Mage::registry('current_category');
            $this->addTag('catalog-category-' . $currentCategory->getId());
        }

        if (is_null(Mage::registry('VARNISH-TAGS'))) {
            $this->_log('nothing to invalidate');
            return;
        }
        $this->_log('invalidating by: ' . implode(',', array_keys(Mage::registry('VARNISH-TAGS'))));
        $observer->getResponse()->setHeader('X-Invalidated-By', implode(',', array_keys(Mage::registry('VARNISH-TAGS'))));
    }

    /**
     * collect tags
     *
     * @param $tag
     */
    public function addTag($tag)
    {
        if (!is_array($tag)) {
            $tag = array($tag);
        }
        $tags = Mage::registry('VARNISH-TAGS');
        if (is_null($tags)) {
            $tags = array();
        } else {
            Mage::unregister('VARNISH-TAGS');
        }
        foreach ($tag as $t) {
            $tags[$t]++;
        }
        Mage::register('VARNISH-TAGS', $tags);
    }
}
