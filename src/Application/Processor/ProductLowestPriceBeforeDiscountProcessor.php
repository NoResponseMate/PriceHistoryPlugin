<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\PriceHistoryPlugin\Application\Processor;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\PriceHistoryPlugin\Domain\Model\ChannelInterface;
use Sylius\PriceHistoryPlugin\Domain\Model\ChannelPricingInterface;
use Sylius\PriceHistoryPlugin\Domain\Model\ChannelPricingLogEntryInterface;
use Sylius\PriceHistoryPlugin\Domain\Repository\ChannelPricingLogEntryRepositoryInterface;
use Webmozart\Assert\Assert;

final class ProductLowestPriceBeforeDiscountProcessor implements ProductLowestPriceBeforeDiscountProcessorInterface
{
    public function __construct(
        private ChannelPricingLogEntryRepositoryInterface $channelPricingLogEntryRepository,
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function process(ChannelPricingInterface $channelPricing): void
    {
        if (!$this->isPromotionApplied($channelPricing)) {
            $channelPricing->setLowestPriceBeforeDiscount(null);

            return;
        }

        $latestLogEntry = $this->channelPricingLogEntryRepository->findLatestOneByChannelPricing($channelPricing);

        if ($latestLogEntry === null) {
            $channelPricing->setLowestPriceBeforeDiscount(null);

            return;
        }

        $channelCode = $channelPricing->getChannelCode();
        Assert::string($channelCode);

        /** @var ChannelInterface $channel */
        $channel = $this->channelRepository->findOneByCode($channelCode);

        if (!$channel instanceof ChannelInterface) {
            return;
        }

        $channelPriceHistoryConfig = $channel->getChannelPriceHistoryConfig();

        $lowestPriceInPeriod = $this->findLowestPriceInPeriod(
            $latestLogEntry,
            $channelPriceHistoryConfig->getLowestPriceForDiscountedProductsCheckingPeriod(),
        );

        $channelPricing->setLowestPriceBeforeDiscount($lowestPriceInPeriod);
    }

    private function isPromotionApplied(ChannelPricingInterface $channelPricing): bool
    {
        return
            $channelPricing->getOriginalPrice() !== null &&
            $channelPricing->getPrice() < $channelPricing->getOriginalPrice()
        ;
    }

    private function findLowestPriceInPeriod(
        ChannelPricingLogEntryInterface $latestLogEntry,
        int $lowestPriceForDiscountedProductsCheckingPeriod,
    ): ?int {
        $loggedAt = new \DateTimeImmutable($latestLogEntry->getLoggedAt()->format('Y-m-d H:i:s'));

        /** @var \DateTimeInterface $startDate */
        $startDate = $loggedAt->sub(new \DateInterval(sprintf('P%sD', $lowestPriceForDiscountedProductsCheckingPeriod)));

        return $this
            ->channelPricingLogEntryRepository
            ->findLowestPriceInPeriod($latestLogEntry->getId(), $latestLogEntry->getChannelPricing(), $startDate)
        ;
    }
}
