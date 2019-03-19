<?php

require_once 'abstract.php';

class Mage_Shell_TaxConfig extends Mage_Shell_Abstract
{
    const DEFAULT_RATE = 20;
    const NO_VAT_RATE = 0;
    const ES_RATE = 20;
    const FR_RATE = 20;
    const IT_RATE = 22;
    const DE_RATE = 22;
    const CH_RATE = 22;


    private $shippingB2BRate;
    private $shippingB2CRate;
    private $bToC;
    private $bToB;
    private $shipping;
    private $wrapping;
    private $noVatRate;
    private $fullVatRate;

    /**
     * Run script
     *
     */
    public function run()
    {
        $this->_loadTaxRules();

        if ($this->getArg('rollback')) {
            $this->_rollback();
            return 0;
        }

        $this
            ->_applyTaxRateForIndividualCountries()
            ->_setFullVatRateForB2bAndB2c()
            ->_setWrappingTaxForGiftWrapProducts()
            ->_deleteConfigsFromDB();

        return 0;
    }

    /**
     * @return $this
     */
    private function _applyTaxRateForIndividualCountries(){
        //get the country
        $country = Mage::getStoreConfig(Mage_Core_Helper_Data::XML_PATH_DEFAULT_COUNTRY);

        //set "Default Country" of "Default Tax Destination Calculation"
        $config = new Mage_Core_Model_Config();
        $config->saveConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_COUNTRY, $country);
        $rate = $this->_getTax($country);

        // Apply the rates
        $this->noVatRate->setTaxCountryId($country);
        $this->noVatRate->setRate(self::NO_VAT_RATE);
        $this->noVatRate->save();

        $this->fullVatRate->setTaxCountryId($country);
        $this->fullVatRate->setRate($rate);
        $this->fullVatRate->save();

        return $this;
    }

    /**
     * @param $country
     * @return int
     */
    protected function _getTax($country)
    {
        //select the tax rate for each country
        switch ($country) {
            case 'ES':
                $rate = self::ES_RATE;
                break;
            case 'FR':
                $rate = self::FR_RATE;
                break;
            case 'IT':
                $rate = self::IT_RATE;
                break;
            case 'DE':
                $rate = self::DE_RATE;
                break;
            case 'CH':
                $rate = self::CH_RATE;
                break;
            default:
                $rate = self::DEFAULT_RATE;
        }
        return $rate;
    }

    /**
     * @return $this
     */
    private function _loadTaxRules()
    {
        // Load the tax rate
        $this->noVatRate = Mage::getModel('tax/calculation_rate')
            ->load('NoVatRate', 'code');
        $this->fullVatRate = Mage::getModel('tax/calculation_rate')
            ->load('Full VAT Rate', 'code');

        //Load the tax Rules
        $this->shippingB2BRate = Mage::getModel('tax/calculation_rule')
            ->load('Shipping B2B Rate', 'code')
            ->getId();
        $this->shippingB2CRate = Mage::getModel('tax/calculation_rule')
            ->load('Shipping B2C Rate', 'code')
            ->getId();
        $this->bToC = Mage::getModel('tax/class')//B2C
        ->load('B2C', 'class_name')
            ->getId();
        $this->bToB = Mage::getModel('tax/class')//B2B
        ->load('B2B', 'class_name')
            ->getId();
        $this->shipping = Mage::getModel('tax/class')
            ->load('Shipping', 'class_name')
            ->getId();
        $this->wrapping = Mage::getModel('tax/class')
            ->load('Wrapping', 'class_name')
            ->getId();

        return $this;
    }

    /**
     * @return $this
     */
    private function _setFullVatRateForB2bAndB2c(){
        //Set fullVatRate for B2C
        Mage::getSingleton('tax/calculation')
            ->deleteByRuleId($this->shippingB2CRate);//delete old config to avoid chain

        $dataArray = array(
            array(//set for Wrapping
                'tax_calculation_rule_id' => $this->shippingB2CRate,
                'tax_calculation_rate_id' => $this->fullVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToC,
                'product_tax_class_id' => $this->wrapping,
            ),
            array(//set for Shipping
                'tax_calculation_rule_id' => $this->shippingB2CRate,
                'tax_calculation_rate_id' => $this->fullVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToC,
                'product_tax_class_id' => $this->shipping,
            )
        );
        foreach ($dataArray as $data) {
            Mage::getSingleton('tax/calculation')
                ->setData($data)
                ->save();
        }

        //Set fullVatRate for B2B
        Mage::getSingleton('tax/calculation')
            ->deleteByRuleId($this->shippingB2BRate);//delete old config to avoid chain
        $dataArray = array(
            array(//set for Wrapping
                'tax_calculation_rule_id' => $this->shippingB2BRate,
                'tax_calculation_rate_id' => $this->fullVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToB,
                'product_tax_class_id' => $this->wrapping,
            ),
            array(//set for Shipping
                'tax_calculation_rule_id' => $this->shippingB2BRate,
                'tax_calculation_rate_id' => $this->fullVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToB,
                'product_tax_class_id' => $this->shipping,
            )
        );
        foreach ($dataArray as $data) {
            Mage::getSingleton('tax/calculation')
                ->setData($data)
                ->save();
        }

        return $this;
    }

    /**
     * @return $this
     */
    private function _setWrappingTaxForGiftWrapProducts()
    {
        //re-set tax_class_id to "wrapping" only for Gift Wrap products
        $stores = Mage::app()->getStores(true);
        foreach ($stores as $store) {
            Mage::app()->setCurrentStore($store->getId());

            $collectionGiftWrapProducts = Mage::getModel('catalog/product')
                ->getCollection()
                ->addFieldToFilter('type_id', Smartbox_Catalog_Model_Product_Type::TYPE_GIFTWRAP);
            $wrapping = Mage::getModel('tax/class')
                ->load('Wrapping', 'class_name')
                ->getId();

            foreach ($collectionGiftWrapProducts as $product) {
                $product->setTaxClassId($wrapping);
            }
            $collectionGiftWrapProducts->save();
        }

        return $this;
    }

    /**
     * @return $this
     */
    private function _deleteConfigsFromDB()
    {
        //delete configs from the DB to make sure that it wont overwrite the configs already set on databags (local.xml)
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_SHIPPING_TAX_CLASS);

        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_PRICE_INCLUDES_TAX);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_SHIPPING_INCLUDES_TAX);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_APPLY_AFTER_DISCOUNT);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DISCOUNT_TAX);

        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_PRICE_DISPLAY_TYPE);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DISPLAY_SHIPPING);

        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_CART_PRICE);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_CART_SUBTOTAL);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_CART_SHIPPING);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_CART_GRANDTOTAL);

        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_SALES_PRICE);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_SALES_SUBTOTAL);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_SALES_SHIPPING);
        Mage::getConfig()->deleteConfig(Mage_Tax_Model_Config::XML_PATH_DISPLAY_SALES_GRANDTOTAL);

        Mage::getConfig()->deleteConfig(Smartbox_Sales_Helper_Data::CATALOG_RULE_DISCOUNTED_PATH);

        return $this;
    }

    /**
     * @return int
     */
    private function _rollback()
    {
        //Set noVatRate for B2C
        Mage::getSingleton('tax/calculation')
            ->deleteByRuleId($this->shippingB2CRate);//delete old config to avoid chain

        $dataArray = array(
            array(//set for Wrapping
                'tax_calculation_rule_id' => $this->shippingB2CRate,
                'tax_calculation_rate_id' => $this->noVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToC,
                'product_tax_class_id' => $this->wrapping,
            ),
            array(//set for Shipping
                'tax_calculation_rule_id' => $this->shippingB2CRate,
                'tax_calculation_rate_id' => $this->noVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToC,
                'product_tax_class_id' => $this->shipping,
            )
        );
        foreach ($dataArray as $data) {
            Mage::getSingleton('tax/calculation')
                ->setData($data)
                ->save();
        }

        //Set noVatRate for B2B
        Mage::getSingleton('tax/calculation')
            ->deleteByRuleId($this->shippingB2BRate);//delete old config to avoid chain
        $dataArray = array(
            array(//set for Wrapping
                'tax_calculation_rule_id' => $this->shippingB2BRate,
                'tax_calculation_rate_id' => $this->noVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToB,
                'product_tax_class_id' => $this->wrapping,
            ),
            array(//set for Shipping
                'tax_calculation_rule_id' => $this->shippingB2BRate,
                'tax_calculation_rate_id' => $this->noVatRate->getTaxCalculationRateId(),
                'customer_tax_class_id' => $this->bToB,
                'product_tax_class_id' => $this->shipping,
            )
        );
        foreach ($dataArray as $data) {
            Mage::getSingleton('tax/calculation')
                ->setData($data)
                ->save();
        }

        //remove Tax_class_id for Gift_Wrap Products
        $stores = Mage::app()->getStores(true);
        foreach ($stores as $store) {
            Mage::app()->setCurrentStore($store->getId());

            $collectionGiftWrapProducts = Mage::getModel('catalog/product')
                ->getCollection()
                ->addFieldToFilter('type_id', Smartbox_Catalog_Model_Product_Type::TYPE_GIFTWRAP);

            foreach ($collectionGiftWrapProducts as $product) {
                $product->setTaxClassId(self::NO_VAT_RATE);
            }
            $collectionGiftWrapProducts->save();
        }

        return 0;
    }

}

$shell = new Mage_Shell_TaxConfig();
$shell->run();
