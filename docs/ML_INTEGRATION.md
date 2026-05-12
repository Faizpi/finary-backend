# ML Integration Plan

Laravel acts as the gateway between the React client and the FINARY AI microservice.

## Source of Truth

Use `docsapifinary.json` as the current OpenAPI contract for the AI service.

Current AI endpoints:

- `POST /classify`
- `POST /predict`
- `POST /recommend-side-hustle`

## Runtime Flow

1. React calls Laravel API only.
2. Laravel calls the AI service through `app/Services/MlGatewayService.php`.
3. If the AI service is unavailable, Laravel falls back to deterministic rule-based logic.
4. React renders the normalized Laravel response, regardless of whether the source is `ml` or `rule-based`.

## Environment Variables

Set in `server/.env` when overriding defaults:

- `ML_ENABLED=true`
- `ML_BASE_URL=https://raamwhy-finary-model.hf.space`
- `ML_TIMEOUT=4`

## Endpoint Mapping

### POST /classify

Laravel caller:

- `FinancialClassifierService`
- `AssessmentController@store`

Request fields:

- `monthly_income`
- `monthly_expense_total`
- `actual_savings`
- `budget_goal`
- `emergency_fund`

Expected labels:

- `survival`
- `stable`
- `growth`

The full classification result is stored in `assessments.metadata.classification_result`.

### POST /predict

Laravel caller:

- `FinancialInsightService@profile`

Request fields are generated once per user per day from dashboard summary plus the latest assessment:

- `income`
- `expense`
- `savings`
- `target_tabungan`
- `loan_payment`
- `emergency_fund`

The result is cached daily and returned in the profile payload as `prediction`.

### POST /recommend-side-hustle

Laravel caller:

- `SideHustleRecommendationService`
- `RecommendationController@sideHustles`

Request fields:

- `experience_level`
- `available_hours_per_week`
- `interest_category`

Responses are normalized to:

- `job_category`
- `platform`
- `project_type`
- `predicted_monthly_earnings_idr`

## Production Notes

- Keep rule-based fallback enabled for reliability.
- Keep Laravel as the only public API consumed by React.
- Add model version and request id when the AI service supports them.
- Consider invalidating the daily prediction cache when a user changes transactions or assessment data.
