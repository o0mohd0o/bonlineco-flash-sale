<?php
namespace Bonlineco\FlashSale\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Form\Element\DataType\Price as UiPrice;
use Magento\Ui\Component\Form\Element\Input;
use Magento\Ui\Component\Form\Element\DataType\Date as UiDate;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\Form\Field;
use Magento\Framework\Stdlib\ArrayManager;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;

class FlashSale extends AbstractModifier
{
    /** @var ArrayManager */
    private $arrayManager;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var LocatorInterface */
    private $locator;

    public function __construct(
        ArrayManager $arrayManager,
        StoreManagerInterface $storeManager,
        LocatorInterface $locator
    ) {
        $this->arrayManager = $arrayManager;
        $this->storeManager = $storeManager;
        $this->locator = $locator;
    }

    public function modifyMeta(array $meta)
    {
        // Ensure advanced pricing modal exists (after core AdvancedPricing modifier runs)
        $advPath = $this->arrayManager->findPath('advanced-pricing', $meta, null, 'children');
        if (!$advPath) {
            return $meta;
        }

        $currencySymbol = $this->locator->getStore()->getBaseCurrency()->getCurrencySymbol();

        // Move existing EAV field for flash_sale_price into Advanced Pricing (if present). Fallback to create.
        $pricePath = $this->arrayManager->findPath('flash_sale_price', $meta, null, 'children');
        if ($pricePath) {
            // Enhance config (currency, validation, types)
            $meta = $this->arrayManager->merge(
                $pricePath . '/arguments/data/config',
                $meta,
                [
                    'label' => __('Flash Sale Price'),
                    'componentType' => Field::NAME,
                    'formElement' => Input::NAME,
                    'dataType' => UiPrice::NAME,
                    'dataScope' => 'flash_sale_price',
                    'sortOrder' => 25,
                    'source' => 'advanced-pricing',
                    'imports' => [
                        '__disableTmpl' => ['addbefore' => false],
                    ],
                    'addbefore' => $currencySymbol,
                    'validation' => [
                        'validate-zero-or-greater' => true,
                    ],
                ]
            );

            // Place it under advanced pricing group
            $meta = $this->arrayManager->set(
                $advPath . '/children/flash_sale_price',
                $meta,
                $this->arrayManager->get($pricePath, $meta)
            );

            // Remove original node to avoid duplicate
            $meta = $this->arrayManager->remove($pricePath, $meta);
        } else {
            // Create field if not generated in meta (edge cases)
            $meta = $this->arrayManager->merge(
                $advPath,
                $meta,
                [
                    'children' => [
                        'flash_sale_price' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'label' => __('Flash Sale Price'),
                                        'componentType' => Field::NAME,
                                        'formElement' => Input::NAME,
                                        'dataType' => UiPrice::NAME,
                                        'dataScope' => 'flash_sale_price',
                                        'sortOrder' => 25,
                                        'source' => 'advanced-pricing',
                                        'imports' => [
                                            '__disableTmpl' => ['addbefore' => false],
                                        ],
                                        'addbefore' => $currencySymbol,
                                        'validation' => [
                                            'validate-zero-or-greater' => true,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        }

        // Grouped Flash Sale Dates similar to Special Price dates
        $datesGroupPath = $advPath . '/children/flash_sale_dates';
        // Create group container (no children yet)
        $meta = $this->arrayManager->set(
            $datesGroupPath,
            $meta,
            [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'componentType' => Container::NAME,
                            'label' => false,
                            'required' => false,
                            'additionalClasses' => 'admin__control-grouped-date',
                            'breakLine' => false,
                            'component' => 'Magento_Ui/js/form/components/group',
                            'sortOrder' => 26,
                        ],
                    ],
                ],
            ]
        );

        // Try to find existing attribute UI nodes and move them under the group to avoid duplicates
        $fromPath = $this->arrayManager->findPath('flash_sale_from_date', $meta, null, 'children');
        if ($fromPath) {
            // Enhance config and move into group
            $meta = $this->arrayManager->merge(
                $fromPath . '/arguments/data/config',
                $meta,
                [
                    'label' => __('Flash Sale From'),
                    'componentType' => Field::NAME,
                    'formElement' => UiDate::NAME,
                    'dataType' => 'date',
                    'dataScope' => 'flash_sale_from_date',
                    'source' => 'advanced-pricing',
                    'additionalClasses' => 'admin__field-date',
                    'options' => [
                        'showsTime' => false,
                    ],
                ]
            );
            $meta = $this->arrayManager->set(
                $datesGroupPath . '/children/flash_sale_from_date',
                $meta,
                $this->arrayManager->get($fromPath, $meta)
            );
            $meta = $this->arrayManager->remove($fromPath, $meta);
        } else {
            // Fallback: create field if meta did not include it
            $meta = $this->arrayManager->merge(
                $datesGroupPath,
                $meta,
                [
                    'children' => [
                        'flash_sale_from_date' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'label' => __('Flash Sale From'),
                                        'componentType' => Field::NAME,
                                        'formElement' => UiDate::NAME,
                                        'dataType' => 'date',
                                        'dataScope' => 'flash_sale_from_date',
                                        'source' => 'advanced-pricing',
                                        'additionalClasses' => 'admin__field-date',
                                        'options' => [
                                            'showsTime' => false,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        }

        $toPath = $this->arrayManager->findPath('flash_sale_to_date', $meta, null, 'children');
        if ($toPath) {
            // Enhance config and move into group
            $meta = $this->arrayManager->merge(
                $toPath . '/arguments/data/config',
                $meta,
                [
                    'label' => __('To'),
                    'componentType' => Field::NAME,
                    'formElement' => UiDate::NAME,
                    'dataType' => 'date',
                    'dataScope' => 'flash_sale_to_date',
                    'source' => 'advanced-pricing',
                    'additionalClasses' => 'admin__field-date',
                    'options' => [
                        'showsTime' => false,
                    ],
                ]
            );
            $meta = $this->arrayManager->set(
                $datesGroupPath . '/children/flash_sale_to_date',
                $meta,
                $this->arrayManager->get($toPath, $meta)
            );
            $meta = $this->arrayManager->remove($toPath, $meta);
        } else {
            // Fallback: create field if meta did not include it
            $meta = $this->arrayManager->merge(
                $datesGroupPath,
                $meta,
                [
                    'children' => [
                        'flash_sale_to_date' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'label' => __('To'),
                                        'componentType' => Field::NAME,
                                        'formElement' => UiDate::NAME,
                                        'dataType' => 'date',
                                        'dataScope' => 'flash_sale_to_date',
                                        'source' => 'advanced-pricing',
                                        'additionalClasses' => 'admin__field-date',
                                        'options' => [
                                            'showsTime' => false,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        }

        return $meta;
    }

    public function modifyData(array $data)
    {
        return $data;
    }
}
