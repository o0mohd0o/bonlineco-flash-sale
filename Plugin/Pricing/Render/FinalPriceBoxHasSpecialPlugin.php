<?php
namespace Bonlineco\FlashSale\Plugin\Pricing\Render;

use Magento\Catalog\Pricing\Render\FinalPriceBox;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\WebsiteInterface;

class FinalPriceBoxHasSpecialPlugin
{
    /** @var TimezoneInterface */
    private $timezone;

    public function __construct(TimezoneInterface $timezone)
    {
        $this->timezone = $timezone;
    }

    /**
     * Force old price rendering when flash sale is active by returning true for hasSpecialPrice.
     *
     * @param FinalPriceBox $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundHasSpecialPrice(FinalPriceBox $subject, callable $proceed)
    {
        $original = (bool) $proceed();

        try {
            $product = $subject->getSaleableItem();
            if (!$product) {
                return $original;
            }

            $flashPrice = $product->getData('flash_sale_price');
            if ($flashPrice === null || $flashPrice === '' || (float)$flashPrice <= 0) {
                // no flash price; continue checks for native special price below
            } else {
                $from = $product->getData('flash_sale_from_date');
                $to   = $product->getData('flash_sale_to_date');

                $active = $this->timezone->isScopeDateInInterval(
                    WebsiteInterface::ADMIN_CODE,
                    $from,
                    $to
                );

                if ($active) {
                    return true;
                }
            }

            // Check native Magento special price date interval
            $special = $product->getData('special_price');
            if ($special !== null && $special !== '' && (float)$special > 0) {
                $sFrom = $product->getData('special_from_date');
                $sTo   = $product->getData('special_to_date');
                $sActive = $this->timezone->isScopeDateInInterval(
                    WebsiteInterface::ADMIN_CODE,
                    $sFrom,
                    $sTo
                );
                
                // Only show special price if it's active AND different from regular price
                if ($sActive) {
                    $regular = $subject->getPriceType('regular_price');
                    if ($regular) {
                        $regVal = (float)$regular->getAmount()->getValue();
                        $specialVal = (float)$special;
                        
                        // Only show if special price is different from regular price
                        if ($specialVal !== $regVal) {
                            return true;
                        }
                    }
                }
            }

            // Fallback: compare final vs regular amounts
            $regular = $subject->getPriceType('regular_price');
            $final   = $subject->getPriceType('final_price');
            if ($regular && $final) {
                $regVal = (float)$regular->getAmount()->getValue();
                $finVal = (float)$final->getAmount()->getValue();
                if ($finVal > 0 && $regVal > 0 && $finVal < $regVal) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // swallow; fall back to original behaviour
        }

        return $original;
    }
}
