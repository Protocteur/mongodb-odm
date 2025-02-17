<?php

declare(strict_types=1);

namespace Documents74;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @template TKey of array-key
 * @template TElement
 * @template-extends ArrayCollection<TKey, TElement>
 */
class CustomCollection extends ArrayCollection
{
}
