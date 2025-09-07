<?php
namespace Bonlineco\FlashSale\Pricing\Price;

use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Adjustment\CalculatorInterface;
use Magento\Framework\Pricing\Price\AbstractPrice;
use Magento\Framework\Pricing\Price\BasePriceProviderInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Psr\Log\LoggerInterface;

class FlashSalePrice extends AbstractPrice implements BasePriceProviderInterface
{
    /**
     * Price type identifier string
     */
    const PRICE_CODE = 'flash_sale_price';

    /** @var TimezoneInterface */
    private $localeDate;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Product $saleableItem,
        float $quantity,
        CalculatorInterface $calculator,
        PriceCurrencyInterface $priceCurrency,
        TimezoneInterface $localeDate,
        LoggerInterface $logger,
        PriceInfoInterface $priceInfo = null
    ) {
        parent::__construct($saleableItem, $quantity, $calculator, $priceCurrency, $priceInfo);
        $this->localeDate = $localeDate;
        $this->logger = $logger;
    }

    /**
     * Get flash sale price value
     *
     * @return float|bool
     */
    public function getValue()
    {
        if ($this->value === null) {
            $this->value = false;
            $product = $this->getProduct();
            $flashPrice = $product->getData('flash_sale_price');

            if ($flashPrice !== null && $flashPrice !== false && $flashPrice !== '') {
                $from = $product->getData('flash_sale_from_date');
                $to = $product->getData('flash_sale_to_date');

                $isActive = $this->localeDate->isScopeDateInInterval(
                    WebsiteInterface::ADMIN_CODE,
                    $from,
                    $to
                );

                if ($isActive) {
                    $this->value = (float) $this->priceCurrency->convertAndRound($flashPrice);
                    $this->logger->info('FlashSalePrice: Applied flash sale price', [
                        'product_id' => $product->getId(),
                        'flash_price' => $this->value,
                        'from_date' => $from,
                        'to_date' => $to
                    ]);
                }
            }
        }

        return $this->value;
    }
}
