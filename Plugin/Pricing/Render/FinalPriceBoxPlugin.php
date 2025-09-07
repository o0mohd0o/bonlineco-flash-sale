<?php
namespace Bonlineco\FlashSale\Plugin\Pricing\Render;

use Magento\Catalog\Pricing\Render\FinalPriceBox as CoreFinalPriceBox;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Api\Data\WebsiteInterface;
use Psr\Log\LoggerInterface;

class FinalPriceBoxPlugin
{
    /** @var TimezoneInterface */
    private $timezone;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var HttpRequest */
    private $request;
    /** @var ProductRepositoryInterface */
    private $productRepository;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        TimezoneInterface $timezone, 
        StoreManagerInterface $storeManager,
        HttpRequest $request,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->timezone = $timezone;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * Append flash sale icon beside price when flash sale is active.
     * Refactored to use a dedicated block/template and avoid brittle HTML manipulation.
     *
     * @param CoreFinalPriceBox $subject
     * @param string $html
     * @return string
     */
    public function afterToHtml(CoreFinalPriceBox $subject, $html)
    {
        if (!$html) {
            return $html;
        }

        $saleable = $subject->getSaleableItem();
        if (!$saleable instanceof Product) {
            return $html;
        }

        try {
            $layout = $subject->getLayout();
            if ($layout) {
                /** @var \Bonlineco\FlashSale\Block\Badge $block */
                $block = $layout->createBlock(\Bonlineco\FlashSale\Block\Badge::class);
                if ($block) {
                    $block->setProduct($saleable);
                    $block->setTemplate('Bonlineco_FlashSale::flashsale/badge.phtml');
                    $badgeHtml = $block->toHtml();
                    if ($badgeHtml) {
                        return $badgeHtml . $html;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('FlashSale badge render error: ' . $e->getMessage());
        }

        return $html;
    }
}
