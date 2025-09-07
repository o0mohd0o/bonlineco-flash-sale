<?php
namespace Bonlineco\FlashSale\Block;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class Badge extends Template
{
    /** @var TimezoneInterface */
    private $timezone;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var ProductRepositoryInterface */
    private $productRepository;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        TimezoneInterface $timezone,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->timezone = $timezone;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function setProduct(Product $product): self
    {
        $this->setData('product', $product);
        return $this;
    }

    public function getProduct(): ?Product
    {
        $product = $this->getData('product');
        return $product instanceof Product ? $product : null;
    }

    public function getIconUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . 'bonlineco/flash-icon.gif';
    }

    public function isConfigurable(): bool
    {
        $p = $this->getProduct();
        return $p && $p->getTypeId() === 'configurable';
    }

    public function isActive(): bool
    {
        $p = $this->getProduct();
        if (!$p) {
            return false;
        }

        // Direct on product
        $active = $this->isProductFlashActive($p);
        if ($active) {
            return true;
        }

        // If configurable, check if any child has an active flash sale
        if ($p->getTypeId() === 'configurable') {
            try {
                $children = $p->getTypeInstance()->getUsedProducts($p);
                foreach ($children as $child) {
                    if ($this->isProductFlashActive($child)) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('FlashSale Badge: children check failed: ' . $e->getMessage());
            }
        }

        return false;
    }

    public function getCountdownEnd(): ?int
    {
        $p = $this->getProduct();
        if (!$p) {
            return null;
        }

        $endTs = null;

        // Prefer parent end if active
        if ($this->isProductFlashActive($p)) {
            $to = $p->getData('flash_sale_to_date');
            if (!empty($to)) {
                $endTs = strtotime($to) ?: null;
            }
        }

        // For configurable, pick the nearest active child's end
        if ($p->getTypeId() === 'configurable') {
            try {
                $children = $p->getTypeInstance()->getUsedProducts($p);
                foreach ($children as $child) {
                    if ($this->isProductFlashActive($child)) {
                        $cto = $child->getData('flash_sale_to_date');
                        $cts = $cto ? strtotime($cto) : null;
                        if ($cts && ($endTs === null || $cts < $endTs)) {
                            $endTs = $cts;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('FlashSale Badge: countdown end failed: ' . $e->getMessage());
            }
        }

        return $endTs;
    }

    public function getActiveChildrenIds(): array
    {
        $p = $this->getProduct();
        $ids = [];
        if ($p && $p->getTypeId() === 'configurable') {
            try {
                $children = $p->getTypeInstance()->getUsedProducts($p);
                foreach ($children as $child) {
                    if ($this->isProductFlashActive($child)) {
                        $ids[] = (int)$child->getId();
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return array_values(array_unique($ids));
    }

    public function getEndsMap(): array
    {
        $p = $this->getProduct();
        $map = [];
        if ($p && $p->getTypeId() === 'configurable') {
            try {
                $children = $p->getTypeInstance()->getUsedProducts($p);
                foreach ($children as $child) {
                    if ($this->isProductFlashActive($child)) {
                        $cto = $child->getData('flash_sale_to_date');
                        $cts = $cto ? strtotime($cto) : null;
                        if ($cts) {
                            $map[(int)$child->getId()] = (int)$cts;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $map;
    }

    private function isProductFlashActive(Product $product): bool
    {
        $flash = $product->getData('flash_sale_price');
        if ($flash === null || $flash === '' || (float)$flash <= 0) {
            // attempt to load fully if minimal instance
            try {
                $product = $this->productRepository->getById((int)$product->getId());
                $flash = $product->getData('flash_sale_price');
            } catch (\Throwable $e) {
                return false;
            }
        }
        if ($flash === null || $flash === '' || (float)$flash <= 0) {
            return false;
        }

        $from = $product->getData('flash_sale_from_date');
        $to   = $product->getData('flash_sale_to_date');

        $active = $this->timezone->isScopeDateInInterval(
            \Magento\Store\Api\Data\WebsiteInterface::ADMIN_CODE,
            $from,
            $to
        );
        if (!$active) {
            return false;
        }

        // Ensure it actually discounts vs regular
        $regularPrice = (float)($product->getPrice() ?: $product->getFinalPrice());
        return $regularPrice > 0 && (float)$flash < $regularPrice;
    }
}
