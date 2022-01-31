<?php

declare(strict_types=1);

namespace Documents\Functional;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\EmbeddedDocument */
class Embedded
{
    /**
     * @ODM\Field(type="string")
     *
     * @var string|null
     */
    public $name;
}
