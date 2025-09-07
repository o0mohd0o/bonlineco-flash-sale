<?php
namespace Bonlineco\FlashSale\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

class SetQuoteItemFlashSalePrice implements ObserverInterface
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
     * Set flash sale price on quote item when product is added to cart
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
        $quoteItem = $observer->getEvent()->getQuoteItem();
        
        if (!$quoteItem || $quoteItem->getParentItem()) {
            return;
        }

        $product = $quoteItem->getProduct();
        
        // Load product with all attributes if needed
        if (!$product->getData('flash_sale_price')) {
            $product->load($product->getId());
        }
        
        $flashSalePrice = $product->getData('flash_sale_price');
        $flashSaleFromDate = $product->getData('flash_sale_from_date');
        $flashSaleToDate = $product->getData('flash_sale_to_date');
        
        $this->logger->info('SetQuoteItemFlashSalePrice: Checking product ' . $product->getId() . ' SKU: ' . $product->getSku());
        $this->logger->info('SetQuoteItemFlashSalePrice: Flash sale price: ' . $flashSalePrice);
        $this->logger->info('SetQuoteItemFlashSalePrice: From date: ' . $flashSaleFromDate);
        $this->logger->info('SetQuoteItemFlashSalePrice: To date: ' . $flashSaleToDate);
        
        if ($flashSalePrice && $this->isFlashSaleActive($flashSaleFromDate, $flashSaleToDate)) {
            $this->logger->info('SetQuoteItemFlashSalePrice: Flash sale is active, setting price to ' . $flashSalePrice);
            
            // Set custom price on quote item
            $quoteItem->setCustomPrice((float)$flashSalePrice);
            $quoteItem->setOriginalCustomPrice((float)$flashSalePrice);
            $quoteItem->setPrice((float)$flashSalePrice);
            $quoteItem->setBasePrice((float)$flashSalePrice);
            $quoteItem->setCalculationPrice((float)$flashSalePrice);
            $quoteItem->setConvertedPrice((float)$flashSalePrice);
            $quoteItem->getProduct()->setIsSuperMode(true);
            
            $this->logger->info('SetQuoteItemFlashSalePrice: Custom price set successfully on quote item ' . $quoteItem->getId());
        } else {
            $this->logger->info('SetQuoteItemFlashSalePrice: Flash sale not active or no flash sale price');
        }
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
        if (!$fromDate || !$toDate) {
            return false;
        }

        $currentDate = $this->timezone->date()->format('Y-m-d H:i:s');
        return $currentDate >= $fromDate && $currentDate <= $toDate;
    }
}
