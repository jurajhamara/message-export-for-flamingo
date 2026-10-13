"""Overí a vypíše súbor .xlsx z exportu, len so štandardnou knižnicou Pythonu.

Každá časť musí byť korektné XML, riadky a bunky v poradí, odkazy buniek
musia sedieť. Potom vypíše hárok, ukotvenie, autofilter, šírky, hlavičku
a riadky ako stĺpec=hodnota[štýl].

  python3 -I dev/dump-xlsx.py export.xlsx           prvé a posledné riadky
  python3 -I dev/dump-xlsx.py export.xlsx all       všetky riadky
  python3 -I dev/dump-xlsx.py export.xlsx 'SUM|🙂'  riadky, ktoré zodpovedajú regexu

Súbor nepatrí do pluginu, priečinok dev/ musí byť v .distignore.
"""
import re
import sys
import zipfile
import datetime
import xml.etree.ElementTree as ET

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
path = sys.argv[1]
show = sys.argv[2] if len(sys.argv) > 2 else 'head'

z = zipfile.ZipFile(path)
assert z.testzip() is None
names = z.namelist()
print('časti:', names)
for n in names:
    ET.fromstring(z.read(n))  # každá časť musí byť korektné XML

wb = ET.fromstring(z.read('xl/workbook.xml'))
sheet_name = wb.find('m:sheets/m:sheet', NS).get('name')
dn = wb.find('m:definedNames/m:definedName', NS)
print('hárok:', sheet_name, '| definedName:', dn.text if dn is not None else None)

ws = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
pane = ws.find('m:sheetViews/m:sheetView/m:pane', NS)
print('ukotvenie:', pane.attrib)
print('autofilter:', ws.find('m:autoFilter', NS).get('ref'))
cols = [(c.get('min'), c.get('width')) for c in ws.findall('m:cols/m:col', NS)]
print('šírky:', cols)


def col_index(ref):
    letters = re.match(r'[A-Z]+', ref).group(0)
    n = 0
    for ch in letters:
        n = n * 26 + ord(ch) - 64
    return n - 1


rows = []
for r_i, row in enumerate(ws.findall('m:sheetData/m:row', NS), start=1):
    assert int(row.get('r')) == r_i, 'poradie riadkov'
    last = -1
    cells = {}
    for c in row.findall('m:c', NS):
        ref = c.get('r')
        assert int(re.search(r'\d+', ref).group(0)) == r_i, 'riadok v odkaze'
        ci = col_index(ref)
        assert ci > last, 'poradie buniek'
        last = ci
        t = c.get('t')
        s = c.get('s')
        if t == 'inlineStr':
            val = ''.join(x.text or '' for x in c.iter('{%s}t' % NS['m']))
        else:
            v = float(c.find('m:v', NS).text)
            val = datetime.datetime(1899, 12, 30) + datetime.timedelta(seconds=round(v * 86400))
        cells[ci] = (val, s, t)
    rows.append(cells)

ncols = len(cols)
print('riadkov (s hlavičkou):', len(rows), '| stĺpcov:', ncols)
header = [rows[0].get(i, ('', None, None))[0] for i in range(ncols)]
print('HLAVIČKA:')
for i, h in enumerate(header):
    print('  %2d %s' % (i, h))


def fmt(cells):
    out = []
    for i in range(ncols):
        if i in cells:
            val, s, t = cells[i]
            out.append('%s=%r[s%s]' % (header[i], val, s))
    return ' | '.join(out)


if show == 'head':
    for cells in rows[1:4]:
        print('-', fmt(cells))
    print('- ...')
    for cells in rows[-2:]:
        print('-', fmt(cells))
elif show == 'all':
    for cells in rows[1:]:
        print('-', fmt(cells))
else:
    for cells in rows[1:]:
        line = fmt(cells)
        if re.search(show, line):
            print('-', line)
