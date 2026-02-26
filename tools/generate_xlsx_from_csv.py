import csv
from openpyxl import Workbook

csv_path = r'data_dictionary.csv'
xlsx_path = r'data_dictionary.xlsx'

wb = Workbook()
ws = wb.active
ws.title = 'Data Dictionary'

with open(csv_path, newline='', encoding='utf-8') as f:
    reader = csv.reader(f)
    for row in reader:
        ws.append(row)

wb.save(xlsx_path)
print('Saved', xlsx_path)
