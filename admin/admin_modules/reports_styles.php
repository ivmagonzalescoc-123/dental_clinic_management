<?php
// Styles for the Clinic Reports pages: the reports themselves (reports.php) and the
// comparison page (reports_compare.php). Screen styles first, then the print layout.
?>
<style>
.clinic-reports-filter {
    background: #f8fafc;
    border-radius: 12px;
}
.clinic-reports-filter .card-body {
    padding: 0.85rem 1rem;
}
.clinic-reports-filter .form-label {
    color: #64748b;
    font-weight: 600;
}
.clinic-reports-filter .form-select,
.clinic-reports-filter .form-control {
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
    font-size: 13px;
}
.clinic-reports-filter .form-select:focus,
.clinic-reports-filter .form-control:focus {
    border-color: #1b7aa3;
    background-color: #fff;
    box-shadow: none;
}
.dental-kpi-card {
    border-radius: 14px;
    border: 1px solid #e9eef2;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: box-shadow 0.18s ease, transform 0.18s ease;
}
.dental-kpi-card:hover {
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
    transform: translateY(-1px);
}
.dental-kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    margin-bottom: 10px;
}
.dental-kpi-icon--teal { background: var(--primary-soft, #eff8fb); color: var(--primary-color, #1b7aa3); }
.dental-kpi-icon--green { background: #dcfce7; color: #16a34a; }
.dental-kpi-icon--red { background: #fee2e2; color: #dc3545; }
.dental-kpi-icon--amber { background: #fef3c7; color: #b45309; }
.dental-kpi-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
    margin-bottom: 3px;
}
.dental-kpi-value {
    font-size: 1.7rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.dental-kpi-value small {
    font-size: 0.85rem;
    font-weight: 600;
    color: #94a3b8;
}
/* Phones: stat cards sit 2-per-row (col-6), so shrink the big figures to fit
   a half-width card, and let an odd card left alone at the end span the row. */
@media (max-width: 767.98px) {
    .row > .col-6:last-child:nth-child(odd):has(> .dental-kpi-card) {
        flex: 0 0 100%;
        max-width: 100%;
    }
    .dental-kpi-card.p-3 {
        padding: 0.8rem !important;
    }
    .dental-kpi-value {
        font-size: 1.2rem;
        overflow-wrap: anywhere;
    }
}
.dental-section-eyebrow {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
    margin: 0 0 0.6rem 2px;
}
.dental-chart-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
.dental-chart-card__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.dental-chart-card__head h5 {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}
.dental-chart-expand-btn {
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 8px;
    color: #64748b;
    cursor: pointer;
}
.dental-chart-frame {
    min-height: 240px;
}
.clinic-report-bar-item {
    margin-bottom: 12px;
}
.clinic-report-bar-track {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}
.clinic-report-bar-fill {
    height: 100%;
    background: var(--primary-color);
    border-radius: 4px;
    transition: width 0.6s ease;
}
.clinic-report-bar-fill.is-service {
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}
.badge {
    font-weight: 500;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 6px;
}
.table th {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}
.table td {
    font-size: 13px;
    vertical-align: middle;
}
.dental-chart-print-table { display: none; }
.clinic-report-print-meta { display: none; }

/* Compact, document-style print output — no cards/icons/shadows, dense tables,
   everything scoped to the report area so on-screen styling is untouched. */
@media print {
    #clinicReportPrintArea.clinic-reports-shell {
        padding: 0;
        font-size: 11px;
        color: #000;
    }
    .clinic-report-no-print { display: none !important; }

    #clinicReportPrintArea .module-page-header h4 { font-size: 15px; margin-bottom: 2px; }
    #clinicReportPrintArea .dental-chip {
        font-size: 10px;
        padding: 1px 6px;
        border: 1px solid #94a3b8;
        background: none !important;
        color: #000;
    }

    #clinicReportPrintArea .clinic-report-print-meta {
        display: block;
        font-size: 10px;
        color: #334155;
        margin: 0 0 8px;
    }

    /* Flatten every card: no border/shadow/radius, tight spacing. */
    #clinicReportPrintArea .card {
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        break-inside: avoid;
        margin-bottom: 4px !important;
    }
    #clinicReportPrintArea .card-body { padding: 2px 0 !important; }
    #clinicReportPrintArea .row { row-gap: 0 !important; }
    #clinicReportPrintArea .row > [class*="col-"] { padding: 1px 4px !important; }

    /* Force any row of KPI tiles into a fixed 4-per-row grid (ignore the
       screen breakpoint classes) so tiles are sized to the row, not stretched
       full-width with mostly-empty space. Tables are untouched — full width. */
    #clinicReportPrintArea .row:has(> [class*="col-"] > .dental-kpi-card) {
        display: flex !important;
        flex-wrap: wrap !important;
    }
    #clinicReportPrintArea .row:has(> [class*="col-"] > .dental-kpi-card) > [class*="col-"] {
        flex: 0 0 25% !important;
        max-width: 25% !important;
    }

    /* KPI cards become one-line "Label: Value" strips instead of padded tiles. */
    #clinicReportPrintArea .dental-kpi-icon { display: none !important; }
    #clinicReportPrintArea .dental-kpi-card {
        display: flex;
        align-items: baseline;
        gap: 4px;
        border: 1px solid #cbd5e1 !important;
        padding: 2px 6px !important;
        width: 100%;
        box-sizing: border-box;
    }
    #clinicReportPrintArea .dental-kpi-label {
        font-size: 9px;
        margin-bottom: 0;
        color: #475569;
        white-space: nowrap;
    }
    #clinicReportPrintArea .dental-kpi-label::after { content: ":"; }
    #clinicReportPrintArea .dental-kpi-value { font-size: 12px; line-height: 1.1; color: #000; }
    #clinicReportPrintArea .dental-kpi-value small { font-size: 9px; }

    #clinicReportPrintArea .dental-section-eyebrow {
        font-size: 9.5px;
        margin: 8px 0 3px;
        color: #000;
        border-bottom: 1px solid #94a3b8;
        padding-bottom: 2px;
    }

    /* Charts are replaced by their data table (see admin-charts.js renderPrintTables). */
    #clinicReportPrintArea .dental-chart-expand-btn,
    #clinicReportPrintArea .clinic-simple-chart { display: none !important; }
    #clinicReportPrintArea .dental-chart-card__head { margin-bottom: 2px; }
    #clinicReportPrintArea .dental-chart-card__head h5,
    #clinicReportPrintArea .card-title { font-size: 11px; font-weight: 700; }
    #clinicReportPrintArea .dental-chart-print-table { display: block !important; }
    #clinicReportPrintArea .dental-chart-print-table table { font-size: 9.5px; margin-bottom: 0; }

    /* A card holding a long table (many rows) must NOT be forced to stay in
       one block — that clips it when it's taller than a page. Let it flow
       across pages instead; only individual rows/table headers stay intact. */
    #clinicReportPrintArea .card:has(table) { break-inside: auto !important; }
    #clinicReportPrintArea table { break-inside: auto; }
    #clinicReportPrintArea tr,
    #clinicReportPrintArea .dental-kpi-card { break-inside: avoid; }
    #clinicReportPrintArea thead { display: table-header-group; }

    /* Dense, direct-to-read data tables. */
    #clinicReportPrintArea .table-responsive { overflow: visible !important; }
    #clinicReportPrintArea .table { font-size: 10px; margin-bottom: 0; }
    #clinicReportPrintArea .table th,
    #clinicReportPrintArea .table td { padding: 3px 5px !important; }
    #clinicReportPrintArea .table th {
        font-size: 9px;
        border-bottom: 1px solid #000;
    }
    #clinicReportPrintArea .badge {
        border: 1px solid #94a3b8;
        background: none !important;
        color: #000 !important;
        padding: 1px 5px;
        font-size: 9px;
    }
}</style>
