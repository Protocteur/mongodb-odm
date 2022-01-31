<?php

declare(strict_types=1);

namespace Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * @ODM\MappedSuperclass
 * @ODM\Indexes({
 *   @ODM\Index(keys={"slug"="asc"}, options={"unique"="true"})
 * })
 */
abstract class CmsPage
{
    /**
     * @ODM\Id
     *
     * @var string|null
     */
    public $id;

    /**
     * @ODM\Field(type="string")
     *
     * @var string|null
     */
    public $slug;
}
