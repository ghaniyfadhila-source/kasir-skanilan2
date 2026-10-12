# Ticket 10: Reports + Export

## Objective
Admin reporting dashboard with daily/weekly/monthly views, charts (Chart.js), and CSV/Excel export.

## Scope
- [ ] Controller: `Admin\ReportController`
- [ ] Routes: `admin.reports.*` (index, export)
- [ ] View: `admin/reports/index.blade.php`
  - Date range picker (Alpine.js + flatpickr CDN or native date inputs)
  - Preset buttons: Today, Yesterday, This Week, Last Week, This Month, Last Month
  - Summary cards: Total Transactions, Total Revenue, Unique Buyers, Avg Transaction
  - Chart 1: Revenue per day (bar, Chart.js)
  - Chart 2: Top 10 menu items (doughnut)
  - Chart 3: Payment method? (all RFID/balance) — skip, maybe by cashier
  - Table: Recent transactions (paginated, sortable)
- [ ] Export:
  - CSV: `transactions_{start}_{end}.csv` — columns: Date, Receipt No, Buyer, Cashier, Items, Total, Status
  - Excel (optional): use `maatwebsite/excel` package
  - Trigger: button "Export CSV" → streaming download
- [ ] API for charts:
  - `GET /admin/reports/data?start=...&end=...` → JSON for Chart.js
  - Cached 5 min (cache key includes date range)

## Acceptance Criteria
- Date picker works, presets update range
- Charts render correctly (responsive)
- CSV export downloads valid UTF-8 CSV (BOM for Excel compatibility)
- Large date ranges (1 year) export without timeout (streaming)
- Summary cards match table totals

## Technical Notes
- Chart.js v4 via CDN: `https://cdn.jsdelivr.net/npm/chart.js`
- Use `raw` SQL or query builder for aggregation performance
- `Transaction::scopeDateRange($start, $end)`
- Revenue = SUM(total_cents) / 100
- Top items: JOIN transaction_items + menu_items GROUP BY menu_item_id
- Cashier performance: GROUP BY cashier_id

## Dependencies
- Ticket 01 (Transaction model)
- Ticket 02 (Admin shell)
- Ticket 07 (Transaction creation)

## Chart.js Config Example
```javascript
new Chart(ctx, {
  type: 'bar',
  data: { labels: dates, datasets: [{ label: 'Revenue (Rp)', data: revenue }] },
  options: { responsive: true, scales: { y: { beginAtZero: true } } }
})
```

## Testing
- `php artisan test --filter=ReportTest`
- Manual: export CSV → open in Excel → verify numbers match dashboard