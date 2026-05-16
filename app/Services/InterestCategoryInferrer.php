<?php

namespace App\Services;

use App\Contracts\InterestCategoryInferrerContract;

class InterestCategoryInferrer implements InterestCategoryInferrerContract
{
    public function infer(array $skills): string
    {
        $skillText = strtolower(implode(' ', $skills));

        return match (true) {
            str_contains($skillText, 'design')                                     => 'Graphic Design',
            str_contains($skillText, 'seo') || str_contains($skillText, 'writing') => 'SEO',
            str_contains($skillText, 'teach')                                      => 'Teaching / Tutoring',
            str_contains($skillText, 'social')                                     => 'Social Media Management',
            default                                                                => 'App Development',
        };
    }
}
