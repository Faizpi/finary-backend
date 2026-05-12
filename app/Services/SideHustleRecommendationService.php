<?php

namespace App\Services;

class SideHustleRecommendationService
{
    private const HUSTLE_CATALOG = [
        [
            'job_category' => 'Freelance Social Media Admin',
            'skills' => ['social media', 'copywriting', 'design'],
            'min_hours' => 8,
            'predicted_monthly_earnings_idr' => 2200000,
            'platform' => 'Instagram',
            'project_type' => 'Content calendar, caption writing, and account operations',
        ],
        [
            'job_category' => 'Tutor Online',
            'skills' => ['teaching', 'communication', 'math', 'english'],
            'min_hours' => 6,
            'predicted_monthly_earnings_idr' => 1800000,
            'platform' => 'Preply',
            'project_type' => 'Private tutoring and structured learning sessions',
        ],
        [
            'job_category' => 'Jasa Desain Konten',
            'skills' => ['design', 'canva', 'illustration'],
            'min_hours' => 6,
            'predicted_monthly_earnings_idr' => 2800000,
            'platform' => 'Fiverr',
            'project_type' => 'Social media templates and brand content packages',
        ],
        [
            'job_category' => 'Admin Marketplace',
            'skills' => ['communication', 'sales', 'spreadsheet'],
            'min_hours' => 10,
            'predicted_monthly_earnings_idr' => 2400000,
            'platform' => 'Shopee',
            'project_type' => 'Product listing, order handling, and customer chat support',
        ],
        [
            'job_category' => 'Penulis Artikel SEO',
            'skills' => ['writing', 'seo', 'research'],
            'min_hours' => 5,
            'predicted_monthly_earnings_idr' => 2100000,
            'platform' => 'Upwork',
            'project_type' => 'SEO blog articles and content optimization',
        ],
        [
            'job_category' => 'Data Entry Project',
            'skills' => ['spreadsheet', 'detail oriented', 'typing'],
            'min_hours' => 4,
            'predicted_monthly_earnings_idr' => 1300000,
            'platform' => 'Freelancer',
            'project_type' => 'Spreadsheet cleanup, catalog entry, and admin data tasks',
        ],
    ];

    public function __construct(private readonly MlGatewayService $mlGateway)
    {
    }

    public function recommend(array $payload): array
    {
        $mlResult = $this->mlGateway->recommendSideHustles($payload);

        if (is_array($mlResult) && isset($mlResult['recommendations']) && is_array($mlResult['recommendations'])) {
            return [
                'source' => 'ml',
                'recommendations' => array_map(fn(array $item) => $this->normalizeRecommendation($item), $mlResult['recommendations']),
            ];
        }

        $skills = array_map('strtolower', (array) ($payload['skills'] ?? [$payload['interest_category'] ?? '']));
        $hours = (int) ($payload['available_hours_per_week'] ?? 0);
        $interest = strtolower((string) ($payload['interest_category'] ?? ''));

        $recommendations = array_map(function (array $item) use ($skills, $hours, $interest) {
            $matchedSkills = array_values(array_intersect($item['skills'], $skills));
            $score = count($matchedSkills) * 20;

            $score += $hours >= $item['min_hours'] ? 20 : -20;

            if ($interest && str_contains(strtolower($item['job_category'] . ' ' . $item['project_type']), $interest)) {
                $score += 25;
            }

            if ($hours <= 6 && $item['min_hours'] <= 6) {
                $score += 10;
            }

            return [
                'job_category' => $item['job_category'],
                'platform' => $item['platform'],
                'project_type' => $item['project_type'],
                'predicted_monthly_earnings_idr' => $item['predicted_monthly_earnings_idr'],
                'matched_skills' => $matchedSkills,
                'match_score' => $score,
                'reason' => $this->buildReason($matchedSkills, $hours, $item['min_hours']),
            ];
        }, self::HUSTLE_CATALOG);

        usort($recommendations, fn(array $a, array $b) => $b['match_score'] <=> $a['match_score']);

        return [
            'source' => 'rule-based',
            'recommendations' => array_slice($recommendations, 0, 5),
        ];
    }

    private function normalizeRecommendation(array $item): array
    {
        return [
            'job_category' => (string) ($item['job_category'] ?? $item['title'] ?? 'Side Hustle'),
            'platform' => (string) ($item['platform'] ?? $item['channel'] ?? 'Freelancer'),
            'project_type' => (string) ($item['project_type'] ?? $item['reason'] ?? 'Flexible freelance project'),
            'predicted_monthly_earnings_idr' => (float) (
                $item['predicted_monthly_earnings_idr']
                ?? $item['estimated_income']['high']
                ?? $item['estimated_income']['low']
                ?? 0
            ),
        ];
    }

    private function buildReason(array $matchedSkills, int $hours, int $minimumHours): string
    {
        $skillText = empty($matchedSkills)
            ? 'skill kamu masih bisa diadaptasi untuk role ini'
            : 'cocok dengan skill: ' . implode(', ', $matchedSkills);

        $hourText = $hours >= $minimumHours
            ? 'waktu luang kamu cukup untuk menjalankan role ini'
            : 'butuh alokasi waktu tambahan agar hasil maksimal';

        return ucfirst($skillText) . '; ' . $hourText . '.';
    }
}
