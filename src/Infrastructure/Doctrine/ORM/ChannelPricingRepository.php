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

namespace Sylius\PriceHistoryPlugin\Infrastructure\Doctrine\ORM;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\PriceHistoryPlugin\Domain\Model\ChannelInterface;
use Sylius\PriceHistoryPlugin\Domain\Model\ChannelPricingLogEntry;

class ChannelPricingRepository extends EntityRepository implements ChannelPricingRepositoryInterface
{
    public function bulkUpdateLowestPricesInChannel(ChannelInterface $channel): void
    {
        $expr = $this->getEntityManager()->getExpressionBuilder();
        $startDateQueryBuilder = $this->createLogEntryQueryBuilder('startDate')
            ->select('date_sub(startDate.loggedAt, :lowestPricePeriod, \'day\')')
            ->andWhere('startDate.channelPricing = o.id')
            ->setParameter('lowestPricePeriod', $channel->getLowestPriceForDiscountedProductsCheckingPeriod())
            ->orderBy('startDate.id', 'DESC')
            ->setMaxResults(1)
        ;

        $lowestPriceBeyondPeriodId = $this->createLogEntryQueryBuilder('lowestPriceBeyondPeriod');
        $lowestPriceBeyondPeriodId
            ->select('lowestPriceBeyondPeriod.id')
            ->andWhere($expr->lt('lowestPriceBeyondPeriod.loggedAt', '(' . $startDateQueryBuilder->getDQL() . ')'))
            ->andWhere('lowestPriceBeyondPeriod.channelPricing = o.id')
            ->orderBy('lowestPriceBeyondPeriod.id', 'DESC')
            ->setMaxResults(1)
        ;
        $lowestPriceBeyondPeriodDql = $lowestPriceBeyondPeriodId->getDQL();

        $mostRecentEntryId = $this->createLogEntryQueryBuilder('mostRecentEntryId')
            ->select('mostRecentEntryId.id')
            ->andWhere('mostRecentEntryId.channelPricing = o.id')
            ->orderBy('mostRecentEntryId.id', 'DESC')
            ->setMaxResults(1)
        ;

        $lowestPriceInPeriodId = $this->createLogEntryQueryBuilder('lowestPriceInPeriod');
        $lowestPriceInPeriodId
            ->select('lowestPriceInPeriod.id')
            ->andWhere($expr->gte('lowestPriceInPeriod.loggedAt', '(' . $startDateQueryBuilder->getDQL() . ')'))
            ->andWhere($expr->neq('lowestPriceInPeriod.id', '(' . $mostRecentEntryId->getDQL() . ')'))
            ->andWhere('lowestPriceInPeriod.channelPricing = o.id')
            ->orderBy('lowestPriceInPeriod.price', 'ASC')
            ->setMaxResults(1)
        ;
        $lowestPriceInPeriodDql = $lowestPriceInPeriodId->getDQL();

        $priceEntryQueryBuilder = $this->createLogEntryQueryBuilder('entry');
        $priceEntryQueryBuilder
            ->select($expr->min('entry.price'))
            ->where($expr->in('entry.id', ['(' . $lowestPriceBeyondPeriodDql . ')', '(' . $lowestPriceInPeriodDql . ')']))
            ->setMaxResults(1)
        ;

        $queryBuilder = $this->createQueryBuilder('o')
            ->update()
            ->set('o.lowestPriceBeforeDiscount', '(' . $priceEntryQueryBuilder->getDQL() . ')')
            ->where('o.channelCode = :channelCode')
            ->setParameters([
                'channelCode' => $channel->getCode(),
            ])
        ;
        $sql = $queryBuilder->getQuery()->getSQL();
        $queryBuilder->getQuery()->execute();
    }

    protected function createLogEntryQueryBuilder(string $alias, string $indexBy = null): QueryBuilder
    {
        return $this->_em->createQueryBuilder()
            ->select($alias)
            ->from(ChannelPricingLogEntry::class, $alias, $indexBy)
        ;
    }
}
