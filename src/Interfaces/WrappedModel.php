<?php
namespace Automatorm\Interfaces;

use Automatorm\Orm\Model;

interface WrappedModel 
{
    /**
     * Return the wrapped Model
     *
     * @return Model
     */
    public function getModel() : Model;
}
