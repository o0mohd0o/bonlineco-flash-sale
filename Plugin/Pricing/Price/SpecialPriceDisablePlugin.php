<?php
namespace Bonlineco\FlashSale\Plugin\Pricing\Price;

use Magento\Catalog\Pricing\Price\SpecialPrice as Subject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\WebsiteInterface;

class SpecialPriceDisablePlugin
{
    /** @var TimezoneInterface */
    private $localeDate;

    public function __construct(
        TimezoneInterface $localeDate
    ) {
        $this->localeDate = $localeDate;
    }

    /**
     * Disable special price when flash sale is active
     *
     * @param Subject $subject
     * @param callable $proceed
     * @return bool|float
     */
    public function aroundGetValue(Subject $subject, callable $proceed)
    {
        $product = $subject->getProduct();
        $flashPrice = $product->getData('flash_sale_price');

        // Check if flash sale is active
        if ($flashPrice !== null && $flashPrice !== false && $flashPrice !== '') {
            $from = $product->getData('flash_sale_from_date');
            $to = $product->getData('flash_sale_to_date');

            $isFlashSaleActive = $this->localeDate->isScopeDateInInterval(
                WebsiteInterface::ADMIN_CODE,
                $from,
                $to
            );

            if ($isFlashSaleActive) {
                // Return false to disable special price when flash sale is active
                return false;
            }
        }

        // Flash sale not active, proceed with normal special price logic
        return $proceed();
    }
}
