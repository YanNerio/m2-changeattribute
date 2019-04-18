<?php
namespace Yan\ChangeAttributeType\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeAttributeType extends Command
{
    const INPUT_CODE = 'code';

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;
    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Eav\Model\Entity\Attribute
     */ 
    protected $_entityAttribute;

    /**
     * EAV setup factory
     *
     * @var EavSetupFactory
     */
    protected $_eavSetupFactory;

    /**
     * EAV product attribute factory
     *
     * @var AttributeFactory
     */
    protected $_attributeFactory;

    protected $action;
    
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\App\State $state,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Eav\Model\Entity\Attribute $entityAttribute,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory $attributeFactory,
        \Magento\Catalog\Model\ResourceModel\Product\Action $productAction
    ) {
        $this->objectManager = $objectManager;
        $this->state = $state;
        $this->_storeManager = $storeManager;
        $this->_entityAttribute = $entityAttribute;
        $this->_eavSetupFactory = $eavSetupFactory;
        $this->_attributeFactory = $attributeFactory;
        $this->_action = $productAction;
        parent::__construct();
    }

    /**
     *
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::INPUT_CODE,
                null,
                InputOption::VALUE_REQUIRED,
                'Attribute Code'
            ),
        ];

        $this->setName('yan:attributes:update_attribute')
            ->setDescription('Change attribute text type to dropdown and reassign values to products')
            ->setDefinition($options);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($attributeCode = $input->getOption(self::INPUT_CODE)) {
            $output->writeln('Updating attribute '.$attributeCode);
            $errors = [];
            $data = [];
            $prodIds = array();
            if (!$this->exposeErrors($errors, $output)) {
                /** @var $db Zend_Db_Adapter_Mysqli */
                $db = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
                $connection = $db->getConnection();
                $eavSetup = $this->_eavSetupFactory->create();
                $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE);
                $attribute = $this->_attributeFactory->create()->loadByCode($entityTypeId, $attributeCode);
                $attributeId = $attribute->getAttributeId();
                
                $optionsToRemove = [];
                
                if($attributeId){
                    $catalog_product_entity_varchar = $db->getTableName('catalog_product_entity_varchar');
                    $attribute_values = $connection->fetchAll("
                        SELECT DISTINCT attribute_id, value 
                        FROM $catalog_product_entity_varchar 
                        WHERE attribute_id = $attributeId");
                    if (!empty($attribute_values)) {                        
                        $options = $attribute->getOptions();
                        foreach($options as $option) {
                            if ($option['value']) {
                                $optionsToRemove['delete'][$option['value']] = true;
                                $optionsToRemove['value'][$option['value']] = true;
                            }
                        }                        
                        try {                         
                            $eavSetup->addAttributeOption($optionsToRemove);
                            $eavSetup->updateAttribute(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, $attributeCode, 'frontend_input','select', null);
                            $eavSetup->updateAttribute(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, $attributeCode,'backend_type','int');
                            $eavSetup->updateAttribute(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, $attributeCode,'source_model','Magento\Eav\Model\Entity\Attribute\Source\Table');

                        } catch (Exception $e) {
                            $output->writeln($e->getMessage());
                        }
                        
                        $optionValue['value'] = array(0=>array());
                        $stores = $this->_storeManager->getStores();
                        $storeArray[0] = "All Store Views";       
                        $optionValue['attribute_id'] = $attributeId;
                        $output->writeln('Creating option values');
                        foreach ($attribute_values as $_attribute_values) { 
                            $optionValue['value'][0][0] = $_attribute_values['value'];
                            foreach ($stores  as $store) {
                                $optionValue['value'][0][$store->getId()] = $_attribute_values['value'];
                            }
                            try {                         
                                $eavSetup->addAttributeOption($optionValue);
                                $output->writeln('Creating option value: '.$_attribute_values['value']);
                            } catch (Exception $e) {
                                $output->writeln($e->getMessage());
                            }
                        }                       
                        
                        $catalog_product_entity_varchar = $connection->getTableName('catalog_product_entity_varchar');
                        $attribute_values = $connection->fetchAll(
                            "SELECT * FROM $catalog_product_entity_varchar 
                             WHERE attribute_id = $attributeId"
                        );
                        if(!empty($attribute_values)) {
                            $eav_attribute_option = $connection->getTableName('eav_attribute_option');
                            $eav_attribute_option_value = $connection->getTableName('eav_attribute_option_value');
                            foreach ($attribute_values as $attribute_prod) 
                            {
                                $prodIds[] = $attribute_prod['row_id'];
                                
                                foreach ($stores  as $store) {
                                    $storeId = $store->getId();
                                    $option_values = $connection->fetchRow(
                                        "SELECT * FROM $eav_attribute_option as eao 
                                        INNER JOIN $eav_attribute_option_value as eaov 
                                        on eao.option_id = eaov.option_id 
                                        WHERE eao.attribute_id = $attributeId 
                                        and eaov.store_id = $storeId
                                        and eaov.value = '$attribute_prod[value]'"
                                    );
                                    if (!empty($option_values)) {
                                        try {
                                            $output->writeln('Saving products attribute value');
                                            $this->_action->updateAttributes([$attribute_prod['row_id']], [$attributeCode => $option_values['option_id']], $storeId);                
                                            $output->writeln("Updating product id: ".$attribute_prod['row_id'] .'--- option value: '.$attribute_prod['value']);    
                                        } catch (Exception $e){
                                            $output->writeln($e->getMessage());
                                        } 
                                    }
                                }                                                               
                            }
                            $output->writeln(count($prodIds)." Products successfully updated");                                                              
                        }
                    } else{
                        $output->writeln('Nothing to change');
                    }
                 } else{
                    $output->writeln('Attribute '. $attributeCode. ' not found');
                 }
            }
        } else {
            $output->writeln(
                "\n\t"
                . "Please specify the parameter --"
                . self::INPUT_CODE
                . " with the attribute id to update from text to dropdown"
                . "\n\t(example: --id=\"123\""
            );
        }
    }

    /**
     * @param array $errors
     * @param OutputInterface $output
     * @return bool
     */
    protected function exposeErrors(array $errors, OutputInterface $output)
    {
        if (!empty($errors)) {
            foreach ($errors as $rowNum => $er) {
                if (!empty($er)) {
                    $output->writeln("Row $rowNum:");
                    foreach ($er as $e) {
                        $output->writeln("\t"
                            . $this->productAttributeImporter->getMessageTemplate($e->getErrorCode()));
                    }
                    $output->writeln("");
                }
            }
            return true;
        }
        return false;
    }
}

?>