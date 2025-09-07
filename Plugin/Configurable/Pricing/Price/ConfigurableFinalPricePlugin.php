<?php
namespace Bonlineco\FlashSale\Plugin\Configurable\Pricing\Price;

use Magento\ConfigurableProduct\Pricing\Price\FinalPrice as Subject;
use Psr\Log\LoggerInterface;

class ConfigurableFinalPricePlugin
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Ensure configurable product uses the minimum final price among its children.
     * This includes flash sale pricing because children's final price is already
     * influenced by ProductGetFinalPricePlugin.
     *
     * @param Subject $subject
     * @param callable $proceed
     * @return float
     */
    public function aroundGetValue(Subject $subject, callable $proceed)
    {
        $value = (float)$proceed();
        $product = $subject->getProduct();

        try {
            if ($product && $product->getTypeId() === 'configurable') {
                $min = $value > 0 ? $value : null;
                $children = $product->getTypeInstance()->getUsedProducts($product);

                foreach ($children as $child) {
                    // Use price framework for child's final price (resolves special & flash sale)
                    $childPriceModel = $child->getPriceInfo()->getPrice('final_price');
                    if ($childPriceModel) {
                        $childFinal = (float)$childPriceModel->getValue();
                        if ($childFinal > 0 && ($min === null || $childFinal < $min)) {
                            $min = $childFinal;
                        }
                    }
                }

                if ($min !== null) {
                    return (float)$min;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('ConfigurableFinalPricePlugin error: ' . $e->getMessage());
        }

        return $value;
    }
}
