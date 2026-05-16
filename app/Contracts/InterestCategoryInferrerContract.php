<?php

namespace App\Contracts;

interface InterestCategoryInferrerContract
{
    /**
     * Infer a side-hustle interest category from a list of skill labels.
     *
     * @param  array<int, string>  $skills
     */
    public function infer(array $skills): string;
}
