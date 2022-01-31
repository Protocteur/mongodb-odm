<?php

declare(strict_types=1);

namespace Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\Document */
class ForumAvatar
{
    /**
     * @ODM\Id
     *
     * @var string|null
     */
    public $id;
}
