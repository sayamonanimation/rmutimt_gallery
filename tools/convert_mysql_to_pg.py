#!/usr/bin/env python3
"""
แปลง MySQL dump ของ phpMyAdmin -> สคริปต์ PostgreSQL (schema + data) สำหรับ Supabase

วิธีใช้:
    python3 tools/convert_mysql_to_pg.py [MYSQL_DUMP.sql] [OUT.sql]

ค่าเริ่มต้น:
    MYSQL_DUMP = ../rmutimt_gallery.sql
    OUT        = database_supabase.sql
    schema     = tools/pg_schema.sql   (แก้โครงสร้างตารางที่ไฟล์นี้)
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = os.path.dirname(HERE)

SRC = sys.argv[1] if len(sys.argv) > 1 else os.path.join(PROJECT, "..", "rmutimt_gallery.sql")
OUT = sys.argv[2] if len(sys.argv) > 2 else os.path.join(PROJECT, "database_supabase.sql")
SCHEMA_PATH = os.path.join(HERE, "pg_schema.sql")

MYSQL_UNESCAPE = {
    "0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t",
    "Z": "\x1a", "\\": "\\", "'": "'", '"': '"', "%": "\\%", "_": "\\_",
}


def unescape_mysql(s: str) -> str:
    out = []
    i = 0
    while i < len(s):
        c = s[i]
        if c == "\\" and i + 1 < len(s):
            nxt = s[i + 1]
            # \% and \_ keep the backslash in MySQL; treat as literal here
            if nxt == "%":
                out.append("%"); i += 2; continue
            if nxt == "_":
                out.append("_"); i += 2; continue
            out.append(MYSQL_UNESCAPE.get(nxt, nxt))
            i += 2
            continue
        out.append(c)
        i += 1
    return "".join(out)


def parse_values(blob: str):
    """Parse a string like "(a,'b'),(c,NULL)" into list of list-of-tokens.
    Each token is either ('str', value) or ('raw', text)."""
    rows = []
    i = 0
    n = len(blob)
    while i < n:
        while i < n and blob[i] in " \t\r\n,":
            i += 1
        if i >= n:
            break
        assert blob[i] == "(", f"expected ( at {i}: {blob[i:i+40]!r}"
        i += 1
        row = []
        while True:
            while i < n and blob[i] in " \t\r\n":
                i += 1
            if blob[i] == "'":
                # string literal with mysql backslash escaping
                i += 1
                start = i
                buf = []
                while i < n:
                    ch = blob[i]
                    if ch == "\\":
                        buf.append(blob[i:i+2]); i += 2; continue
                    if ch == "'":
                        # could be doubled '' -> literal quote
                        if i + 1 < n and blob[i+1] == "'":
                            buf.append("'"); i += 2; continue
                        break
                    buf.append(ch); i += 1
                assert blob[i] == "'"
                i += 1
                raw = "".join(buf)
                row.append(("str", unescape_mysql(raw)))
            else:
                # raw token until , or )
                start = i
                depth = 0
                while i < n and not (blob[i] in ",)" and depth == 0):
                    i += 1
                tok = blob[start:i].strip()
                row.append(("raw", tok))
            while i < n and blob[i] in " \t\r\n":
                i += 1
            if blob[i] == ",":
                i += 1
                continue
            if blob[i] == ")":
                i += 1
                break
        rows.append(row)
    return rows


def pg_literal(tok):
    kind, val = tok
    if kind == "raw":
        if val.upper() == "NULL":
            return "NULL"
        return val  # numbers
    return "'" + val.replace("'", "''") + "'"


def main():
    text = open(SRC, encoding="utf-8").read()

    # Grab every INSERT INTO `tbl` (`c1`,...) VALUES <blob>;
    pattern = re.compile(
        r"INSERT INTO `(?P<tbl>\w+)`\s*\((?P<cols>[^)]*)\)\s*VALUES\s*(?P<vals>.*?);\s*(?=\n(?:--|INSERT|\Z|/\*|SET|ALTER))",
        re.DOTALL,
    )

    tables = {}
    order = []
    for m in pattern.finditer(text):
        tbl = m.group("tbl")
        cols = [c.strip().strip("`") for c in m.group("cols").split(",")]
        rows = parse_values(m.group("vals"))
        if tbl not in tables:
            tables[tbl] = {"cols": cols, "rows": []}
            order.append(tbl)
        tables[tbl]["rows"].extend(rows)

    # normalize projects.status values
    if "projects" in tables:
        cols = tables["projects"]["cols"]
        si = cols.index("status")
        for r in tables["projects"]["rows"]:
            if r[si][0] == "str":
                r[si] = ("str", r[si][1].strip().lower())

    emit_order = ["academic_years", "advisors", "categories", "users",
                  "projects", "project_members", "system_settings", "project_files"]
    order = [t for t in emit_order if t in tables] + [t for t in order if t not in emit_order]

    out = []
    for tbl in order:
        info = tables[tbl]
        cols = info["cols"]
        out.append(f"-- data: {tbl} ({len(info['rows'])} rows)")
        collist = ", ".join(f'"{c}"' for c in cols)
        for r in info["rows"]:
            vals = ", ".join(pg_literal(t) for t in r)
            out.append(f"INSERT INTO {tbl} ({collist}) VALUES ({vals});")
        out.append("")

    body = "\n".join(out)

    schema = open(SCHEMA_PATH, encoding="utf-8").read()

    seq_fix = []
    for tbl in order:
        if "id" in tables[tbl]["cols"]:
            seq_fix.append(
                f"SELECT setval(pg_get_serial_sequence('{tbl}','id'), "
                f"GREATEST((SELECT COALESCE(MAX(id),0) FROM {tbl}), 1));"
            )
    seq_fix = "\n".join(seq_fix)

    final = f"""{schema}

-- ============================================================
--  DATA (migrated from rmutimt_gallery.sql / MySQL)
-- ============================================================
BEGIN;

{body}
-- reset identity sequences
{seq_fix}

COMMIT;
"""
    open(OUT, "w", encoding="utf-8").write(final)
    print("wrote", OUT, len(final), "bytes")
    for tbl in order:
        print(f"  {tbl}: {len(tables[tbl]['rows'])} rows, cols={tables[tbl]['cols']}")


if __name__ == "__main__":
    main()
