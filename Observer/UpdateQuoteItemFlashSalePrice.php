<?php
namespace Bonlineco\FlashSale\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\RequestInterface;

class UpdateQuoteItemFlashSalePrice implements ObserverInterface
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
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     * @param ProductRepositoryInterface $productRepository
     * @param RequestInterface $request
     */
    public function __construct(
        TimezoneInterface $timezone,
        LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        RequestInterface $request
    ) {
        $this->timezone = $timezone;
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->request = $request;
    }

    /**
     * Update flash sale price on existing quote items
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getQuote();
        
        if (!$quote) {
            return;
        }

        // Check if we're in admin order creation and custom prices are being set
        $isAdminOrderCreation = $this->isAdminOrderCreation();
        $requestData = $this->request->getPost();
        $itemsWithCustomPrice = [];
        
        $this->logger->info('UpdateQuoteItemFlashSalePrice: Admin order check', [
            'isAdminOrderCreation' => $isAdminOrderCreation,
            'module' => $this->request->getModuleName(),
            'controller' => $this->request->getControllerName(), 
            'action' => $this->request->getActionName(),
            'has_post_data' => !empty($requestData),
            'has_item_data' => isset($requestData['item'])
        ]);
        
        if ($isAdminOrderCreation && $requestData && isset($requestData['item'])) {
            foreach ($requestData['item'] as $itemId => $itemData) {
                if (isset($itemData['custom_price']) && $itemData['custom_price'] !== '') {
                    $itemsWithCustomPrice[$itemId] = (float)$itemData['custom_price'];
                    $this->logger->info('UpdateQuoteItemFlashSalePrice: Admin custom price detected for item ' . $itemId . ': ' . $itemData['custom_price']);
                }
            }
        }

        foreach ($quote->getAllVisibleItems() as $item) {
            try {
                // Load product with all attributes first
                $product = $this->productRepository->getById($item->getProductId());
                
                $flashSalePrice = $product->getData('flash_sale_price');
                
                // Debug existing price information
                $this->logger->info('UpdateQuoteItemFlashSalePrice: Price debugging for item ' . $item->getId(), [
                    'custom_price' => $item->getCustomPrice(),
                    'price' => $item->getPrice(),
                    'original_custom_price' => $item->getOriginalCustomPrice(),
                    'calculation_price' => $item->getCalculationPrice(),
                    'has_data_custom_price' => $item->hasData('custom_price'),
                    'custom_price_data' => $item->getData('custom_price'),
                    'flash_sale_price' => $flashSalePrice
                ]);
                
                // Skip flash sale override if admin custom price is set for this item (either from POST or already exists)
                $hasAdminCustomPrice = $isAdminOrderCreation && (
                    isset($itemsWithCustomPrice[$item->getId()]) || 
                    ($item->getCustomPrice() && $item->getCustomPrice() > 0 && $item->getCustomPrice() != $flashSalePrice)
                );
                
                if ($hasAdminCustomPrice) {
                    $this->logger->info('UpdateQuoteItemFlashSalePrice: Skipping flash sale for item ' . $item->getId() . ' - admin custom price detected', [
                        'from_post' => isset($itemsWithCustomPrice[$item->getId()]),
                        'existing_custom_price' => $item->getCustomPrice(),
                        'regular_price' => $item->getPrice(),
                        'flash_sale_price' => $flashSalePrice
                    ]);
                    continue;
                }
                $flashSaleFromDate = $product->getData('flash_sale_from_date');
                $flashSaleToDate = $product->getData('flash_sale_to_date');
                
                $this->logger->info('UpdateQuoteItemFlashSalePrice: Checking item ' . $item->getId() . ' Product: ' . $product->getSku());
                $this->logger->info('UpdateQuoteItemFlashSalePrice: Flash sale price: ' . $flashSalePrice);
                
                if ($flashSalePrice && $this->isFlashSaleActive($flashSaleFromDate, $flashSaleToDate)) {
                    $this->logger->info('UpdateQuoteItemFlashSalePrice: Flash sale active, updating price to ' . $flashSalePrice);
                    
                    // Update custom price on quote item
                    $item->setCustomPrice((float)$flashSalePrice);
                    $item->setOriginalCustomPrice((float)$flashSalePrice);
                    $item->setPrice((float)$flashSalePrice);
                    $item->setBasePrice((float)$flashSalePrice);
                    $item->setCalculationPrice((float)$flashSalePrice);
                    $item->setConvertedPrice((float)$flashSalePrice);
                    $item->getProduct()->setIsSuperMode(true);
                    
                    // Force recalculation
                    $item->calcRowTotal();
                    
                    $this->logger->info('UpdateQuoteItemFlashSalePrice: Price updated successfully');
                } else {
                    // Remove custom price if flash sale is not active (but not in admin order creation)
                    if ($item->getCustomPrice() && !$isAdminOrderCreation) {
                        $this->logger->info('UpdateQuoteItemFlashSalePrice: Flash sale not active, removing custom price');
                        $item->setCustomPrice(null);
                        $item->setOriginalCustomPrice(null);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('UpdateQuoteItemFlashSalePrice: Error updating item ' . $item->getId() . ': ' . $e->getMessage());
            }
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

    /**
     * Check if we're in admin order creation mode
     *
     * @return bool
     */
    private function isAdminOrderCreation(): bool
    {
        $moduleName = $this->request->getModuleName();
        $controllerName = $this->request->getControllerName();
        $actionName = $this->request->getActionName();
        
        return $moduleName === 'sales' && 
               in_array($controllerName, ['order_create', 'order_edit']) && 
               in_array($actionName, ['loadBlock', 'save', 'index']);
    }
}
