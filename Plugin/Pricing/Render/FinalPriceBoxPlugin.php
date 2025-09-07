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
     * Updated with stricter validation logic.
     *
     * @param CoreFinalPriceBox $subject
     * @param string $html
     * @return string
     */
    public function afterToHtml(CoreFinalPriceBox $subject, $html)
    {
        // Avoid double-injection
        if (!$html || strpos($html, 'flash-sale-icon') !== false) {
            return $html;
        }

        $saleable = $subject->getSaleableItem();
        if (!$saleable instanceof Product) {
            return $html;
        }

        $flashPrice = $saleable->getData('flash_sale_price');
        $from = $saleable->getData('flash_sale_from_date');
        $to = $saleable->getData('flash_sale_to_date');

        // For simple products, reload if attribute may not be present on the lightweight instance
        if ($saleable->getTypeId() !== 'configurable' && ($flashPrice === null || $flashPrice === '' || (float)$flashPrice <= 0)) {
            try {
                $loaded = $this->productRepository->getById((int)$saleable->getId());
                $flashPrice = $loaded->getData('flash_sale_price');
                $from = $loaded->getData('flash_sale_from_date');
                $to = $loaded->getData('flash_sale_to_date');
            } catch (\Throwable $e) {
                // ignore reload fail
            }
        }

        $hasActiveFlash = false;
        $countdownEndTs = null;
        $activeChildIds = [];
        $childEnds = [];

        // Direct flash price on the item - STRICT VALIDATION
        if ($flashPrice !== null && $flashPrice !== '' && (float)$flashPrice > 0) {
            $flashPriceFloat = (float)$flashPrice;
            $regularPrice = $saleable->getPrice() ?: $saleable->getFinalPrice();
            
            // Debug log for investigation and immediate HTML output
            $this->logger->info('Flash Sale Debug: Product ' . $saleable->getId() . ' - Type: ' . $saleable->getTypeId() . ', Flash: ' . $flashPriceFloat . ', Regular: ' . $regularPrice . ', From: ' . ($from ?: 'null') . ', To: ' . ($to ?: 'null'));
            
            // Add visible debug info in HTML (temporary)
            $html .= '<!-- FLASH DEBUG: Product ' . $saleable->getId() . ' | Type: ' . $saleable->getTypeId() . ' | Flash: ' . $flashPriceFloat . ' | Regular: ' . $regularPrice . ' | From: ' . ($from ?: 'null') . ' | To: ' . ($to ?: 'null') . ' -->';
            
            // STRICT: Only proceed if flash price is meaningful AND has proper date range OR significant discount
            if ($flashPriceFloat >= 0.01 && $regularPrice && $flashPriceFloat < $regularPrice) {
                $isActive = false;
                
                // For simple products: REQUIRE dates to be set for flash sale to be active
                if ($saleable->getTypeId() !== 'configurable') {
                    // Simple products MUST have proper date range
                    if (!empty($from) && !empty($to)) {
                        $isActive = $this->timezone->isScopeDateInInterval(
                            WebsiteInterface::ADMIN_CODE,
                            $from,
                            $to
                        );
                        $this->logger->info('Flash Sale Debug: Simple product ' . $saleable->getId() . ' - Date check result: ' . ($isActive ? 'ACTIVE' : 'INACTIVE'));
                    } else {
                        $this->logger->info('Flash Sale Debug: Simple product ' . $saleable->getId() . ' - REJECTED: Missing date range');
                    }
                } else {
                    // For configurables, allow date checking 
                    if (!empty($from) || !empty($to)) {
                        $isActive = $this->timezone->isScopeDateInInterval(
                            WebsiteInterface::ADMIN_CODE,
                            $from,
                            $to
                        );
                    }
                }
                
                if ($isActive) {
                    $hasActiveFlash = true;
                    if (!empty($to)) {
                        $countdownEndTs = strtotime($to);
                    }
                    $this->logger->info('Flash Sale Debug: Product ' . $saleable->getId() . ' - APPROVED for flash sale');
                } else {
                    $this->logger->info('Flash Sale Debug: Product ' . $saleable->getId() . ' - REJECTED: Not active');
                }
            } else {
                $this->logger->info('Flash Sale Debug: Product ' . $saleable->getId() . ' - REJECTED: Invalid price comparison');
            }
        }

        // If not directly active and/or product is configurable, check children for any active flash sale
        if ($saleable->getTypeId() === 'configurable') {
            try {
                $children = $saleable->getTypeInstance()->getUsedProducts($saleable);
                foreach ($children as $child) {
                    $cPrice = $child->getData('flash_sale_price');
                    if ($cPrice === null || $cPrice === '' || (float)$cPrice <= 0) {
                        // reload child with full attributes (listing context may not load custom attributes)
                        try {
                            $loadedChild = $this->productRepository->getById((int)$child->getId());
                            $cPrice = $loadedChild->getData('flash_sale_price');
                            $child = $loadedChild; // use loaded for from/to as well
                        } catch (\Throwable $e) {
                            // ignore reload fail
                        }
                    }
                    if ($cPrice !== null && $cPrice !== '' && (float)$cPrice > 0) {
                        $cFrom = $child->getData('flash_sale_from_date');
                        $cTo   = $child->getData('flash_sale_to_date');
                        $active = $this->timezone->isScopeDateInInterval(
                            WebsiteInterface::ADMIN_CODE,
                            $cFrom,
                            $cTo
                        );
                        if ($active) {
                            $hasActiveFlash = true;
                            $activeChildIds[] = (int)$child->getId();
                            if (!empty($cTo)) {
                                $cEnd = strtotime($cTo);
                                // choose nearest countdown end among children
                                if ($cEnd && ($countdownEndTs === null || $cEnd < $countdownEndTs)) {
                                    $countdownEndTs = $cEnd;
                                }
                                if ($cEnd) {
                                    $childEnds[(int)$child->getId()] = (int)$cEnd;
                                }
                            }
                        }
                    }
                    // don't break; collect all active children to allow per-selection toggle
                }
            } catch (\Throwable $e) {
                // ignore child detection errors and fall back
            }
        }

        if (!$hasActiveFlash) {
            return $html;
        }

        // Build flash sale icon using media image to show above the price box
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $productId = $saleable->getId();
        $dataActive = htmlspecialchars(json_encode(array_values(array_unique($activeChildIds))), ENT_QUOTES, 'UTF-8');
        $dataEnds = htmlspecialchars(json_encode($childEnds), ENT_QUOTES, 'UTF-8');

        $wrapperDataAttrs = '';
        $initialDisplay = 'display:block;';
        if ($saleable->getTypeId() === 'configurable') {
            $wrapperDataAttrs = ' data-product-id="' . $productId . '" data-active-children="' . $dataActive . '" data-ends="' . $dataEnds . '"';
            // Hide initially for configurables; JS will show only when selected simple is in activeChildren
            $initialDisplay = 'display:none;';
        }

        $icon = '<span class="flash-sale-icon" title="Flash Sale" aria-label="Flash Sale" ' . $wrapperDataAttrs
            . ' style="' . $initialDisplay . ';margin:0;line-height:0;">'
            . '<img src="' . $mediaUrl . 'bonlineco/flash-icon.gif" alt="Flash Sale" '
            . 'style="width:100px;height:40px;display:inline-block;object-fit:contain;vertical-align:middle;" />';

        // Add countdown element if we have an end time (parent or nearest child)
        if (!empty($countdownEndTs)) {
            $endTimestamp = (int)$countdownEndTs;
            $icon .= '<div class="flash-sale-countdown" id="flash-countdown-' . $productId . '" '
                . 'style="font-size:16px;color:#ff4444;font-weight:bold; margin-top:10px; margin-bottom:10px;" '
                . 'data-product-id="' . $productId . '" data-end-time="' . $endTimestamp . '">Loading...</div>';
        }

        // Add JavaScript for countdown and for toggling icon/countdown visibility on configurables
        $script = <<<'SCRIPT'
<script type="text/javascript">
require(['jquery'], function($) {
    var pid = PID_PLACEHOLDER;
    
    var wrapEl = $(".flash-sale-icon[data-product-id='" + pid + "']");
    console.log("FLASH DEBUG JS: Found wrapEl:", wrapEl.length);
    var countEl = $("#flash-countdown-" + pid);
    
    function updateCountdown() {
        if (!countEl.length || !wrapEl.is(":visible")) { return; }
        var endAttr = parseInt(countEl.attr("data-end-time"), 10) || 0;
        if (!endAttr) { return; }
        var endTime = endAttr * 1000;
        var now = new Date().getTime();
        var timeLeft = endTime - now;
        if (timeLeft > 0) {
            var days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
            var hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            var display = "";
            if (days > 0) display += days + "d ";
            if (hours > 0) display += hours + "h ";
            if (minutes > 0) display += minutes + "m ";
            display += seconds + "s";
            countEl.text("⏰ " + display);
        } else {
            countEl.text("Flash Sale Ended");
            clearInterval(window["flashSaleInterval" + pid]);
        }
    }
    
    function checkFlashSaleVisibility() {
        var activeChildren = [];
        var endsMap = {};
        try { activeChildren = JSON.parse(wrapEl.attr("data-active-children") || "[]"); } catch(e) {}
        try { endsMap = JSON.parse(wrapEl.attr("data-ends") || "{}"); } catch(e) {}
        
        var priceBoxEl = wrapEl.closest(".price-box");
        var oldPriceEl = priceBoxEl.find('[data-price-type="oldPrice"]').closest(".old-price");
        
        // Look for flash sale price indicators in the DOM
        var finalPriceEl = priceBoxEl.find('[data-price-type="finalPrice"] .price');
        var oldPriceAmountEl = priceBoxEl.find('[data-price-type="oldPrice"] .price');
        
        console.log("FLASH DEBUG JS: Checking visibility - finalPrice exists:", finalPriceEl.length, "oldPrice exists:", oldPriceAmountEl.length);
        
        // If there's both a final price and old price visible, assume flash sale is active
        if (finalPriceEl.length && oldPriceAmountEl.length && oldPriceAmountEl.is(':visible')) {
            var finalPrice = parseFloat(finalPriceEl.text().replace(/[^0-9.]/g, ''));
            var oldPrice = parseFloat(oldPriceAmountEl.text().replace(/[^0-9.]/g, ''));
            
            console.log("FLASH DEBUG JS: Price comparison - final:", finalPrice, "old:", oldPrice);
            
            if (finalPrice > 0 && oldPrice > 0 && finalPrice < oldPrice) {
                console.log("FLASH DEBUG JS: Flash sale detected - showing icon");
                wrapEl.show();
                if (oldPriceEl.length) { oldPriceEl.show(); }
                return;
            }
        }
        
        // Default: hide the icon
        console.log("FLASH DEBUG JS: No flash sale detected - hiding icon");
        wrapEl.hide();
        if (oldPriceEl.length) { oldPriceEl.hide(); }
    }
    
    // Initial check
    wrapEl.hide(); // Start hidden
    setTimeout(checkFlashSaleVisibility, 500); // Give time for prices to load
    
    // Listen for price updates
    $(document).on('price-updated-' + pid, checkFlashSaleVisibility);
    
    // Also check on any price box mutations
    if (typeof MutationObserver !== 'undefined') {
        var priceBox = wrapEl.closest('.price-box')[0];
        if (priceBox) {
            var observer = new MutationObserver(function(mutations) {
                var shouldCheck = false;
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' || mutation.type === 'attributes') {
                        shouldCheck = true;
                    }
                });
                if (shouldCheck) {
                    setTimeout(checkFlashSaleVisibility, 100);
                }
            });
            observer.observe(priceBox, { childList: true, subtree: true, attributes: true });
        }
    }
    
    // Start countdown tick every second
    if (!window["flashSaleInterval" + pid]) {
        window["flashSaleInterval" + pid] = setInterval(function(){
            updateCountdown();
        }, 1000);
    }
});
    </script>
SCRIPT;

        // For configurables, include the swatch-based toggle script; for simples, include a minimal countdown updater only
        if ($saleable->getTypeId() === 'configurable') {
            $icon .= str_replace('PID_PLACEHOLDER', (string)(int)$productId, $script);
        } else {
            // Minimal countdown script for simple products
            if (!empty($countdownEndTs)) {
                $simpleScript = <<<'SSCRIPT'
<script type="text/javascript">
require(['jquery'], function($) {
    var pid = PID_PLACEHOLDER;
    var countEl = $("#flash-countdown-" + pid);
    function updateCountdown() {
        if (!countEl.length) { return; }
        var endAttr = parseInt(countEl.attr("data-end-time"), 10) || 0;
        if (!endAttr) { return; }
        var endTime = endAttr * 1000;
        var now = new Date().getTime();
        var timeLeft = endTime - now;
        if (timeLeft > 0) {
            var days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
            var hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            var display = "";
            if (days > 0) display += days + "d ";
            if (hours > 0) display += hours + "h ";
            if (minutes > 0) display += minutes + "m ";
            display += seconds + "s";
            countEl.text("⏰ " + display);
        } else {
            countEl.text("Flash Sale Ended");
            clearInterval(window["flashSaleInterval" + pid]);
        }
    }
    updateCountdown();
    if (!window["flashSaleInterval" + pid]) {
        window["flashSaleInterval" + pid] = setInterval(function(){ updateCountdown(); }, 1000);
    }
});
</script>
SSCRIPT;
                $icon .= str_replace('PID_PLACEHOLDER', (string)(int)$productId, $simpleScript);
            }
        }
        
        $icon .= '</span>';

        // Remove the "Regular Price" label and ensure old price is visible with strikethrough
        try {
            // Remove any price-label span
            $html = preg_replace('/<span[^>]*class=\"[^\"]*price-label[^\"]*\"[^>]*>.*?<\\/span>/si', '', $html);
            // Reveal old price by removing no-display class patterns for all products
            $html = str_replace('old-price sly-old-price no-display', 'old-price sly-old-price', $html);
            $html = str_replace('old-price no-display', 'old-price', $html);
            // Add strikethrough styling to old price amounts
            if (strpos($html, 'bonlineco-flashsale-oldprice-css') === false) {
                $icon .= '<style id="bonlineco-flashsale-oldprice-css">.price-box [data-price-type="oldPrice"] .price{ text-decoration: line-through; }</style>';
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Prefer to insert icon at the very top of the price box container so it appears above prices
        $injected = false;

        // Try inserting right after opening <div class="price-box ..."> tag
        $boxPos = strpos($html, 'class="price-box');
        if ($boxPos === false) {
            $boxPos = strpos($html, "class='price-box");
        }
        if ($boxPos !== false) {
            $openTagEnd = strpos($html, '>', $boxPos);
            if ($openTagEnd !== false) {
                $html = substr_replace($html, '>' . $icon, $openTagEnd, 1);
                $injected = true;
            }
        }

        // If that failed (markup differs), fallback to appending after the final price wrapper
        // Try detecting the final price wrapper by attribute or id
        $attrPos = strpos($html, 'data-price-type="finalPrice"');
        if ($attrPos === false) {
            $attrPos = strpos($html, "data-price-type='finalPrice'");
        }
        if ($attrPos === false) {
            $attrPos = strpos($html, 'id="product-price-');
        }

        if (!$injected && $attrPos !== false) {
            // The final price markup usually has nested spans, append after the outer wrapper.
            $firstClose = strpos($html, '</span>', $attrPos);
            if ($firstClose !== false) {
                $secondClose = strpos($html, '</span>', $firstClose + 7);
                if ($secondClose !== false) {
                    $html = substr_replace($html, '</span>' . $icon, $secondClose, strlen('</span>'));
                    $injected = true;
                }
            }
        }

        if (!$injected) {
            // Fallback: append after the last closing span in the block
            $lastPos = strrpos($html, '</span>');
            if ($lastPos !== false) {
                $html = substr_replace($html, '</span>' . $icon, $lastPos, strlen('</span>'));
                $injected = true;
            }
        }

        return $html;
    }
}
