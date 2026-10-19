// gen_docx.js
// Place this file in: Voting System FINAL/gen_docx.js
// Run: node gen_docx.js <type> <json_file> <output_file> <logo1_path> <logo2_path>
// type: "credentials" | "export"

// ── Load docx from local node_modules (Windows XAMPP compatible) ──
const path = require('path');
const fs   = require('fs');

// Look for docx in: same dir, then parent dirs
function findDocx() {
    let dir = __dirname;
    for (let i = 0; i < 5; i++) {
        const candidate = path.join(dir, 'node_modules', 'docx');
        if (fs.existsSync(candidate)) return candidate;
        dir = path.dirname(dir);
    }
    return 'docx'; // fallback to global
}

const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    ImageRun, Header, AlignmentType, BorderStyle, WidthType, ShadingType,
    VerticalAlign, UnderlineType
} = require(findDocx());

const [,, type, dataFile, outputFile, logo1Path, logo2Path] = process.argv;

if (!type || !dataFile || !outputFile || !logo1Path || !logo2Path) {
    console.error('Usage: node gen_docx.js <type> <json_file> <output_file> <logo1> <logo2>');
    process.exit(1);
}

const data  = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
const logo1 = fs.readFileSync(logo1Path); // OLLCF seal (422x426)
const logo2 = fs.readFileSync(logo2Path); // CCS logo  (734x714)

// ── Constants ─────────────────────────────────────────────────────────────────
const FONT     = 'Times New Roman';
const BLUE_HDR = '0066FF';   // header title blue
const NAVY     = '0F1E4A';   // table header navy
const GRAY     = '4A4A4A';
const BLACK    = '000000';
const TABLE_W  = 9360;       // US Letter, 1" margins each side (DXA)

const noBorder = { style: BorderStyle.NONE,   size: 0, color: 'FFFFFF' };
const thinBorder= { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' };
const noBorders = { top: noBorder, bottom: noBorder, left: noBorder, right: noBorder, insideH: noBorder, insideV: noBorder };
const cellBorders = { top: thinBorder, bottom: thinBorder, left: thinBorder, right: thinBorder };

// ── Helpers ───────────────────────────────────────────────────────────────────
function txt(text, opts = {}) {
    return new TextRun({
        text:    String(text ?? ''),
        font:    opts.font   ?? FONT,
        size:    opts.size   ?? 22,
        bold:    opts.bold   ?? false,
        italics: opts.italic ?? false,
        color:   opts.color  ?? BLACK,
    });
}

function para(children, opts = {}) {
    return new Paragraph({
        alignment: opts.align ?? AlignmentType.LEFT,
        spacing:   opts.spacing ?? { before: 0, after: 0 },
        children:  Array.isArray(children) ? children : [children],
    });
}

function cell(children, opts = {}) {
    return new TableCell({
        borders:       opts.borders ?? cellBorders,
        width:         { size: opts.width ?? TABLE_W, type: WidthType.DXA },
        shading:       opts.fill ? { fill: opts.fill, type: ShadingType.CLEAR } : undefined,
        margins:       { top: 80, bottom: 80, left: 120, right: 120 },
        verticalAlign: opts.valign ?? VerticalAlign.CENTER,
        columnSpan:    opts.span,
        children,
    });
}

// ── Header (exact vms.docx format) ───────────────────────────────────────────
// Layout: [OLLCF seal | CCS logo] [MIS title / VMS / OLLCF name]
// Two logos left, text block right — matching the original docx header

function makeHeader() {

    // Left cell: two logos stacked inline
    const logoCell = cell([
        new Paragraph({
            spacing: { before: 0, after: 0 },
            children: [
                new ImageRun({
                    data: logo1,
                    type: 'png',
                    transformation: { width: 44, height: 44 },
                }),
                new TextRun({ text: '  ' }),
                new ImageRun({
                    data: logo2,
                    type: 'png',
                    transformation: { width: 44, height: 44 },
                }),
            ],
        }),
    ], {
        width:   1440,
        borders: noBorders,
        valign:  VerticalAlign.CENTER,
    });

    // Right cell: three lines of text
    const textCell = cell([
        para([txt('Management Information System & IT Services', {
            font: 'Arial',
            size: 28,
            bold: true,
            color: BLUE_HDR,
        })], { spacing: { before: 0, after: 20 } }),

        para([txt('Voting Management System (CCS)', {
            font: 'Arial',
            size: 20,
        })], { spacing: { before: 0, after: 20 } }),

        para([txt('Our Lady of Lourdes College Foundation', {
            font: 'Arial',
            size: 20,
            bold: true,
        })], { spacing: { before: 0, after: 0 } }),
    ], {
        width:   7920,
        borders: noBorders,
        valign:  VerticalAlign.CENTER,
    });

    const headerTable = new Table({
        width:        { size: TABLE_W, type: WidthType.DXA },
        columnWidths: [1440, 7920],
        borders:      noBorders,
        rows: [
            new TableRow({ children: [logoCell, textCell] }),
        ],
    });

    // Blue divider line using paragraph bottom border
    const divider = new Paragraph({
        border: {
            bottom: { style: BorderStyle.SINGLE, size: 14, color: '2F5496', space: 2 }
        },
        spacing: { before: 60, after: 60 },
        children: [],
    });

    return new Header({ children: [headerTable, divider] });
}

// ── Credentials document ──────────────────────────────────────────────────────
function makeCredentialsDoc(data) {
    const { voters = [], generated_date, election_title, school_name } = data;

    const titleBlock = [
        para([txt(school_name || 'Our Lady of Lourdes College Foundation', {
            size: 26, bold: true,
        })], { align: AlignmentType.CENTER, spacing: { before: 160, after: 60 } }),

        para([txt('VOTER CREDENTIALS', {
            size: 30, bold: true, color: NAVY,
        })], { align: AlignmentType.CENTER, spacing: { before: 60, after: 60 } }),

        election_title
            ? para([txt(`Election: ${election_title}`, { size: 20, italic: true, color: GRAY })],
                   { align: AlignmentType.CENTER, spacing: { before: 40, after: 40 } })
            : para([], { spacing: { before: 0, after: 0 } }),

        para([txt(`Date Generated: ${generated_date}`, { size: 20, color: GRAY })],
             { align: AlignmentType.CENTER, spacing: { before: 40, after: 180 } }),
    ];

    let tableRows;
    let colWidths;

    if (voters.length === 1) {
        // Single voter — vertical card layout
        const v = voters[0];
        const fields = [
            ['Voter ID',       v.voter_id,       'Courier New', false],
            ['Password',       v.password,       'Courier New', true],
            ['Full Name',      v.full_name,       FONT,          false],
            ['Grade/Section',  v.grade_section || '—', FONT,    false],
        ];
        colWidths = [2200, 7160];
        tableRows = fields.map(([label, value, font, bold]) =>
            new TableRow({
                children: [
                    cell([para([txt(label, { bold: true, size: 22 })])],
                         { width: 2200, fill: 'EBF0FF', borders: cellBorders }),
                    cell([para([txt(value, { size: 22, font, bold })])],
                         { width: 7160, borders: cellBorders }),
                ]
            })
        );
    } else {
        // Bulk — table with header row
        colWidths = [400, 1700, 1700, 3200, 2360];
        const hRow = new TableRow({
            tableHeader: true,
            children: [
                cell([para([txt('#',              { bold: true, size: 20, color: 'FFFFFF' })])], { width: 400,  fill: NAVY, borders: cellBorders }),
                cell([para([txt('Voter ID',       { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1700, fill: NAVY, borders: cellBorders }),
                cell([para([txt('Password',       { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1700, fill: NAVY, borders: cellBorders }),
                cell([para([txt('Full Name',      { bold: true, size: 20, color: 'FFFFFF' })])], { width: 3200, fill: NAVY, borders: cellBorders }),
                cell([para([txt('Grade/Section',  { bold: true, size: 20, color: 'FFFFFF' })])], { width: 2360, fill: NAVY, borders: cellBorders }),
            ]
        });

        const dRows = voters.map((v, i) => {
            const shade = i % 2 === 0 ? 'F5F7FF' : 'FFFFFF';
            return new TableRow({
                children: [
                    cell([para([txt(String(i + 1),          { size: 20 })])], { width: 400,  fill: shade, borders: cellBorders }),
                    cell([para([txt(v.voter_id,              { size: 20, font: 'Courier New' })])], { width: 1700, fill: shade, borders: cellBorders }),
                    cell([para([txt(v.password,              { size: 20, font: 'Courier New', bold: true })])], { width: 1700, fill: shade, borders: cellBorders }),
                    cell([para([txt(v.full_name,             { size: 20 })])], { width: 3200, fill: shade, borders: cellBorders }),
                    cell([para([txt(v.grade_section || '—', { size: 20 })])], { width: 2360, fill: shade, borders: cellBorders }),
                ]
            });
        });
        tableRows = [hRow, ...dRows];
    }

    const credTable = new Table({
        width:        { size: TABLE_W, type: WidthType.DXA },
        columnWidths: colWidths,
        rows:         tableRows,
    });

    const warning = para([
        txt('CONFIDENTIAL — Passwords are shown only once. Keep this document secure.', {
            size: 18, italic: true, color: 'C62828',
        }),
    ], { align: AlignmentType.CENTER, spacing: { before: 220, after: 0 } });

    return new Document({
        styles: { default: { document: { run: { font: FONT, size: 22 } } } },
        sections: [{
            properties: {
                page: {
                    size:   { width: 12240, height: 15840 },
                    margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 },
                }
            },
            headers: { default: makeHeader() },
            children: [...titleBlock, credTable, warning],
        }]
    });
}

// ── Export (voters list) document ─────────────────────────────────────────────
function makeExportDoc(data) {
    const { voters = [], generated_date, school_name, election_title } = data;

    const titleBlock = [
        para([txt(school_name || 'Our Lady of Lourdes College Foundation', {
            size: 26, bold: true,
        })], { align: AlignmentType.CENTER, spacing: { before: 160, after: 60 } }),

        para([txt('REGISTERED VOTERS LIST', {
            size: 30, bold: true, color: NAVY,
        })], { align: AlignmentType.CENTER, spacing: { before: 60, after: 60 } }),

        election_title
            ? para([txt(`Election: ${election_title}`, { size: 20, italic: true, color: GRAY })],
                   { align: AlignmentType.CENTER, spacing: { before: 40, after: 40 } })
            : para([], { spacing: { before: 0, after: 0 } }),

        para([txt(`Date Generated: ${generated_date}     |     Total Voters: ${voters.length}`, {
            size: 20, color: GRAY,
        })], { align: AlignmentType.CENTER, spacing: { before: 40, after: 180 } }),
    ];

    const colWidths = [400, 1450, 2300, 2100, 1600, 1100, 1410];

    const hRow = new TableRow({
        tableHeader: true,
        children: [
            cell([para([txt('#',             { bold: true, size: 20, color: 'FFFFFF' })])], { width: 400,  fill: NAVY, borders: cellBorders }),
            cell([para([txt('Voter ID',      { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1450, fill: NAVY, borders: cellBorders }),
            cell([para([txt('Full Name',     { bold: true, size: 20, color: 'FFFFFF' })])], { width: 2300, fill: NAVY, borders: cellBorders }),
            cell([para([txt('Election',      { bold: true, size: 20, color: 'FFFFFF' })])], { width: 2100, fill: NAVY, borders: cellBorders }),
            cell([para([txt('Grade/Section', { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1600, fill: NAVY, borders: cellBorders }),
            cell([para([txt('Status',        { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1100, fill: NAVY, borders: cellBorders }),
            cell([para([txt('Voted At',      { bold: true, size: 20, color: 'FFFFFF' })])], { width: 1410, fill: NAVY, borders: cellBorders }),
        ]
    });

    const dRows = voters.map((v, i) => {
        const shade = i % 2 === 0 ? 'F5F7FF' : 'FFFFFF';
        const statusColor = (v.status || '').toLowerCase() === 'voted' ? 'C62828' : '1B5E20';
        return new TableRow({
            children: [
                cell([para([txt(String(i + 1),          { size: 18 })])], { width: 400,  fill: shade, borders: cellBorders }),
                cell([para([txt(v.voter_id,              { size: 18, font: 'Courier New' })])], { width: 1450, fill: shade, borders: cellBorders }),
                cell([para([txt(v.full_name,             { size: 18 })])], { width: 2300, fill: shade, borders: cellBorders }),
                cell([para([txt(v.election_title || '—', { size: 18 })])], { width: 2100, fill: shade, borders: cellBorders }),
                cell([para([txt(v.grade_section || '—', { size: 18 })])], { width: 1600, fill: shade, borders: cellBorders }),
                cell([para([txt(v.status || '—',        { size: 18, bold: true, color: statusColor })])], { width: 1100, fill: shade, borders: cellBorders }),
                cell([para([txt(v.voted_at || '—',      { size: 18 })])], { width: 1410, fill: shade, borders: cellBorders }),
            ]
        });
    });

    const voterTable = new Table({
        width:        { size: TABLE_W, type: WidthType.DXA },
        columnWidths: colWidths,
        rows:         [hRow, ...dRows],
    });

    return new Document({
        styles: { default: { document: { run: { font: FONT, size: 22 } } } },
        sections: [{
            properties: {
                page: {
                    size:   { width: 12240, height: 15840 },
                    margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 },
                }
            },
            headers: { default: makeHeader() },
            children: [...titleBlock, voterTable],
        }]
    });
}

// ── Generate & write ──────────────────────────────────────────────────────────
const doc = type === 'credentials' ? makeCredentialsDoc(data) : makeExportDoc(data);

Packer.toBuffer(doc)
    .then(buf => {
        fs.writeFileSync(outputFile, buf);
        process.stdout.write('OK:' + outputFile + '\n');
    })
    .catch(err => {
        process.stderr.write('ERROR:' + err.message + '\n');
        process.exit(1);
    });
