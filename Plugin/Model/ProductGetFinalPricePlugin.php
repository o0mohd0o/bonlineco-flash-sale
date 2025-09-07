<?php
namespace Bonlineco\FlashSale\Plugin\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Psr\Log\LoggerInterface;

class ProductGetFinalPricePlugin
{
    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * Around plugin for getFinalPrice to ensure flash sale price is used
     *
     * @param Product $subject
     * @param callable $proceed
     * @param float|null $qty
     * @return float
     */
    public function aroundGetFinalPrice(Product $subject, callable $proceed, $qty = null)
    {
        // Get the original final price
        $finalPrice = $proceed($qty);
        
        // Check if flash sale is active
        $flashSalePrice = $subject->getData('flash_sale_price');
        $flashSaleFromDate = $subject->getData('flash_sale_from_date');
        $flashSaleToDate = $subject->getData('flash_sale_to_date');
        
        if ($flashSalePrice && $this->isFlashSaleActive($flashSaleFromDate, $flashSaleToDate)) {
            $this->logger->info('ProductGetFinalPricePlugin: Flash sale active for product ' . $subject->getId());
            $this->logger->info('ProductGetFinalPricePlugin: Original final price: ' . $finalPrice);
            $this->logger->info('ProductGetFinalPricePlugin: Flash sale price: ' . $flashSalePrice);
            
            // Use flash sale price if it's lower than the current final price
            if ($flashSalePrice < $finalPrice) {
                $this->logger->info('ProductGetFinalPricePlugin: Using flash sale price');
                return (float) $flashSalePrice;
            }
        }
        
        return $finalPrice;
    }

    /**
     * Check if flash sale is currently active
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return bool
     */
    private function isFlashSaleActive($fromDate, $toDate): bool
    {
        // Use Magento helper which supports open-ended intervals (from only or to only)
        // and handles timezone correctly.
        return $this->timezone->isScopeDateInInterval(
            WebsiteInterface::ADMIN_CODE,
            $fromDate,
            $toDate
        );
    }
}
