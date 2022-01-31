<?php

declare(strict_types=1);

namespace Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\Document */
class Category extends BaseCategory
{
    /**
     * @ODM\Id
     *
     * @var string|null
     */
    private $id;
}
