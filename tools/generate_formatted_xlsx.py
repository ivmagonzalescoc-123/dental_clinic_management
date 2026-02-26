import csv
import re
from collections import defaultdict
from openpyxl import Workbook
from openpyxl.styles import Font, Alignment


def sanitize_sheet_name(name):
    name = re.sub(r"[\\\\\/*?:\[\]]", "_", name)
    return name[:31]


def read_csv(path):
    rows = []
    with open(path, newline='', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for r in reader:
            rows.append(r)
    return rows


def group_by_table(rows):
    tables = defaultdict(list)
    for r in rows:
        tbl = r.get('Table') or r.get('table') or r.get('Table Name') or ''
        tables[tbl].append(r)
    return tables


def generate_summary(table, rows):
    t = (table or '').lower()
    cols = [ (r.get('Column') or r.get('column') or r.get('Field') or r.get('Field Name') or '').lower() for r in rows ]
    cols_text = ' '.join(cols)
    if 'user' in t or 'account' in t or 'username' in cols_text:
        return 'Stores the login credentials and personal details of all system users.'
    if 'patient' in t or 'patients' in t:
        return 'Stores patient demographic and contact information.'
    if 'appointment' in t or 'appointments' in t:
        return 'Stores appointment records including patient, schedule, and status.'
    if 'billing' in t or 'payment' in t or 'payments' in t:
        return 'Stores billing and payment records for services rendered.'
    if 'clinical' in t or 'medical' in t or 'records' in t:
        return 'Stores clinical records and patient encounter details.'
    if 'service' in t and 'schedule' not in t:
        return 'Stores service definitions, pricing, and metadata.'
    if 'doctor' in t or 'physician' in t:
        return 'Stores doctor profiles, credentials, and availability.'
    if 'notification' in t or 'notifications' in t or 'reminder' in t:
        return 'Stores notifications and reminders sent to users.'
    if 'queue' in t:
        return 'Stores patient queue entries and their statuses.'
    # fallback
    return f'Stores records for the {table} entity.'


def pick_table_description(table, rows):
    # Prefer a Notes / Description field that is non-empty across rows
    for r in rows:
        for key in ('Notes', 'notes', 'Description', 'description'):
            v = r.get(key)
            if v and v.strip():
                return v.strip()
    # generate short summary when none provided
    return generate_summary(table, rows)


def key_label(r):
    pk = (r.get('PK') or r.get('pk') or r.get('Primary Key') or '').strip()
    fk = (r.get('FK') or r.get('fk') or r.get('Foreign Key') or '').strip()
    if pk and pk.upper() in ('YES', 'Y', '1', 'PK'):
        return 'PK'
    if fk and fk.upper() in ('YES', 'Y', '1'):
        return 'FK'
    # fallback: if References has value, mark FK
    if r.get('References'):
        return 'FK'
    return ''


def make_field_description(table, r, key):
    field = (r.get('Column') or r.get('column') or r.get('Field') or r.get('Field Name') or '')
    fld = field.lower()
    tbl = table

    # Primary key
    if key == 'PK':
        return f"Primary key of the {tbl} table."

    # If explicit references provided
    refs = (r.get('References') or r.get('references') or '').strip()
    if refs:
        return f"Foreign key referencing {refs}."

    # common name heuristics
    if any(x in fld for x in ('first_name', 'last_name', 'middle_name', 'fullname', 'name')):
        return f"{field.replace('_', ' ').capitalize()} of the {tbl} table."
    if 'email' in fld:
        return f"Email address of the {tbl} record."
    if 'phone' in fld or 'mobile' in fld:
        return f"Contact phone number for the {tbl} record."
    if 'date' in fld or 'time' in fld:
        return f"{field.replace('_', ' ').capitalize()} for the {tbl} record."
    if any(x in fld for x in ('amount', 'price', 'fee', 'cost', 'total')):
        return f"Monetary amount related to the {tbl} record."
    if 'status' in fld:
        return f"Status of the {tbl} record."
    if 'notes' in fld or 'remark' in fld or 'comment' in fld or 'description' in fld:
        return f"Additional notes or description for the {tbl} record."

    # fallback: describe as field of table
    return f"{field} of the {tbl} table."


def make_workbook(tables):
    wb = Workbook()
    # remove default sheet
    default = wb.active
    wb.remove(default)

    for idx, (table, rows) in enumerate(sorted(tables.items()), start=1):
        sheet_name = sanitize_sheet_name(table or f'Table_{idx}')
        ws = wb.create_sheet(title=sheet_name)

        title_font = Font(bold=True, size=12)
        header_font = Font(bold=True)
        wrap = Alignment(wrapText=True, vertical='top')

        # Title row like "1. User_Account"
        ws.merge_cells('A1:D1')
        ws['A1'] = f"{idx}. {table}"
        ws['A1'].font = title_font

        # Description row
        desc = pick_table_description(table, rows)
        ws.merge_cells('A2:D2')
        ws['A2'] = f"Description: {desc}" if desc else "Description:"
        ws['A2'].alignment = wrap

        # Header row
        header_row = 4
        headers = ['Field Name', 'Data Type', 'Key', 'Description']
        for col_idx, h in enumerate(headers, start=1):
            cell = ws.cell(row=header_row, column=col_idx, value=h)
            cell.font = header_font
            cell.alignment = wrap

        # Data rows
        for i, r in enumerate(rows, start=1):
            row_num = header_row + i
            field = r.get('Column') or r.get('column') or r.get('Field') or r.get('Field Name') or ''
            dtype = r.get('DataType') or r.get('Datatype') or r.get('data_type') or r.get('Type') or ''
            key = key_label(r)
            desc_text = make_field_description(table, r, key)

            ws.cell(row=row_num, column=1, value=field)
            ws.cell(row=row_num, column=2, value=dtype)
            ws.cell(row=row_num, column=3, value=key)
            cdesc = ws.cell(row=row_num, column=4, value=desc_text)
            cdesc.alignment = wrap

        # adjust column widths
        ws.column_dimensions['A'].width = 30
        ws.column_dimensions['B'].width = 18
        ws.column_dimensions['C'].width = 8
        ws.column_dimensions['D'].width = 60

    return wb


def main():
    import os
    csv_path = os.path.join(os.path.dirname(__file__), '..', 'data_dictionary.csv')
    csv_path = os.path.abspath(csv_path)
    if not os.path.exists(csv_path):
        print(f"CSV not found at {csv_path}")
        return

    rows = read_csv(csv_path)
    tables = group_by_table(rows)
    wb = make_workbook(tables)
    base = os.path.join(os.path.dirname(__file__), '..')
    out = os.path.abspath(os.path.join(base, 'data_dictionary_formatted.xlsx'))
    try:
        wb.save(out)
        print(f"Saved {out}")
        return
    except PermissionError:
        pass

    # fallback to timestamped filename to avoid locked file collisions
    from datetime import datetime
    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    out_ts = os.path.abspath(os.path.join(base, f'data_dictionary_formatted_{ts}.xlsx'))
    try:
        wb.save(out_ts)
        print(f"Saved to {out_ts} (original file may be open)")
        return
    except PermissionError:
        # last resort: write to temp file in tools
        out_tmp = os.path.abspath(os.path.join(os.path.dirname(__file__), f'data_dictionary_tmp_{ts}.xlsx'))
        wb.save(out_tmp)
        print(f"Saved to temporary file {out_tmp} (original file may be open)")


if __name__ == '__main__':
    main()
