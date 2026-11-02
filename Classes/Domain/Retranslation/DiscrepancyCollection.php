<?php

namespace Sitegeist\LostInTranslation\Domain\Retranslation;

class DiscrepancyCollection
{
    /**
     * @var Discrepancy[]
     */
    public $items = [];


    public function __construct(Discrepancy ...$items) {
        $this->items = $items;
    }

    public function getEarliestDiscrepancy(): ?Discrepancy
    {
        if (count($this->items) === 0) {
            return null;
        }
        return array_reduce(
            $this->items,
            function (?Discrepancy $result, Discrepancy $item):Discrepancy {
                if ($result === null) {
                    return $item;
                } elseif ($result->discrepancyDate < $item->discrepancyDate) {
                    return $result;
                } else {
                    return $item;
                }
            },
            null
        );
    }

    public function addOneDiscrepancy(Discrepancy $discrepancy): void
    {
        $this->items[] = $discrepancy;
    }

    public function addMultipleDiscrepancies(Discrepancy ...$discrepancies): void
    {
        $this->items = array_merge($this->items, $discrepancies);
    }

    public function asArray(): array
    {
        return array_map(
            fn(Discrepancy $item) => [
                'sourceNodeContextPath' => $item->sourceNode->getContextPath(),
                'targetNodeContextPath' => $item->targetNode?->getContextPath(),
                'dateModified' => $item->discrepancyDate->format(\DateTime::ATOM),
                'description' => $item->description,
                ],
            $this->items
        );
    }
}
