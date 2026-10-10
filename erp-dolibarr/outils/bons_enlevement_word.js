// Carnet de bons d'enlèvement payés, au format Word (2 bons par feuille A4).
// Usage : node bons_enlevement_word.js [premier=1] [nombre=10] [fichier.docx]
//         Variables facultatives pour pré-remplir le premier bon :
//         CLIENT="KERDJA BILEL" PRODUIT=B8 QUANTITE=7040
const fs = require('fs');
const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, BorderStyle,
  AlignmentType, ShadingType, PageBreak, TabStopType } = require('docx');

const premier = parseInt(process.argv[2] || '1', 10);
const nombre = parseInt(process.argv[3] || '10', 10);
const sortie = process.argv[4] || 'BONS_ENLEVEMENT.docx';
const pre = { client: process.env.CLIENT || '', produit: process.env.PRODUIT || '', quantite: process.env.QUANTITE || '' };

const LARGEUR = 10466;            // A4 (11906) − marges 720 × 2
const MONO = 'DejaVu Sans Mono', SANS = 'DejaVu Sans', BRIQUE = 'B4472B';
const t = (text, o = {}) => new TextRun({ text, font: o.mono ? MONO : SANS, size: o.size || 19, bold: o.bold, italics: o.italics, color: o.color });
const para = (runs, o = {}) => new Paragraph({ children: Array.isArray(runs) ? runs : [runs], alignment: o.align, spacing: { before: o.before || 0, after: o.after ?? 50 }, border: o.border, tabStops: o.tabs });
const sans = { top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' } };
const trait = { style: BorderStyle.SINGLE, size: 6, color: '333333' };
const pointille = { style: BorderStyle.DOTTED, size: 6, color: '555555' };
const cadre = b => ({ top: b, bottom: b, left: b, right: b });
const cellule = (enfants, w, o = {}) => new TableCell({ children: enfants, width: { size: w, type: WidthType.DXA }, borders: o.borders || cadre(trait), shading: o.fond ? { type: ShadingType.CLEAR, color: 'auto', fill: o.fond } : undefined, margins: { top: 50, bottom: 50, left: 100, right: 100 }, columnSpan: o.span, verticalAlign: o.va });
// ligne « Libellé : valeur ou pointillés à remplir au stylo »
const champ = (lib, val) => para([t(lib, { size: 18 }), t(val ? '  ' + val : '  ' + '.'.repeat(Math.max(10, 64 - lib.length)), { mono: true, size: 18, bold: !!val })], { after: 150 });

function bon(n, rempli) {
  const no = String(n).padStart(4, '0');
  const enTete = new Table({
    width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [5200, 5266],
    rows: [new TableRow({ children: [
      cellule([para([t('DPR', { bold: true, size: 40 }), t(' AXXAM', { bold: true, size: 22, color: BRIQUE })], { after: 0 }),
               para(t('Briqueterie · produits rouges', { italics: true, size: 15 }), { after: 0 })], 5200, { borders: sans }),
      cellule([para(t('SARL DPR AXXAM · ZAC Helouane, Ighzer Amokrane (Béjaïa)', { size: 14 }), { align: AlignmentType.RIGHT, after: 0 }),
               para(t('RC 08B0185858-06/00 · NIF 000806018585831', { size: 14 }), { align: AlignmentType.RIGHT, after: 0 }),
               para(t('Tél. +213 34 19 21 21 · dpr.axxam2022@gmail.com', { size: 14 }), { align: AlignmentType.RIGHT, after: 0 })], 5266, { borders: sans, va: 'bottom' }),
    ] })],
  });
  const titre = new Table({
    width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [7466, 3000],
    rows: [new TableRow({ children: [
      cellule([para(t("BON D'ENLÈVEMENT PAYÉ", { mono: true, bold: true, italics: true, size: 28 }), { after: 0 }),
               para(t('Marchandise réglée d\'avance — à présenter au chargement', { size: 15, italics: true }), { after: 0 })], 7466, { borders: sans }),
      cellule([para(t('N° ' + no, { mono: true, bold: true, size: 30 }), { align: AlignmentType.CENTER, after: 0 })], 3000, { va: 'center' }),
    ] })],
  });
  const produits = new Table({
    width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [1600, 5466, 3400],
    rows: [
      new TableRow({ children: ['Produit', 'Désignation', 'Quantité à enlever'].map((h, i) => cellule([para(t(h, { size: 16 }), { align: AlignmentType.CENTER, after: 0 })], [1600, 5466, 3400][i], { fond: 'F0F0F0' })) }),
      new TableRow({ children: [
        cellule([para(t(rempli.produit || '', { mono: true, bold: true }), { after: 0 })], 1600),
        cellule([para(t(rempli.produit ? ({ B8: 'Brique creuse 8 trous (B8)', B12: 'Brique creuse 12 trous (B12)', HOURDIS: 'Hourdis (entrevous) 16' }[rempli.produit] || rempli.produit) : '☐ B8   ☐ B12   ☐ Hourdis', { mono: true }), { after: 0 })], 5466),
        cellule([para(t(rempli.quantite ? Number(rempli.quantite).toLocaleString('fr-FR').replace(/ /g, ' ') + ' pièces' : '', { mono: true, bold: true, size: 24 }), { align: AlignmentType.RIGHT, after: 0 })], 3400),
      ] }),
    ],
  });
  const bas = new Table({
    width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [3489, 3489, 3488],
    rows: [
      new TableRow({ children: ['Cachet de la société (DPR AXXAM)', 'Visa du magasinier au chargement', 'SERVI — cadre réservé à l\'usine'].map((h, i) =>
        cellule([para(t(h, { size: 15, italics: true, bold: i === 2, color: i === 2 ? BRIQUE : undefined }), { align: AlignmentType.CENTER, after: 0 }),
                 ...(i === 2 ? [para(t('Servi le ..../..../........', { size: 15, mono: true }), { before: 450, align: AlignmentType.CENTER, after: 0 }),
                                para(t('Classé en comptabilité ☐', { size: 15 }), { before: 200, align: AlignmentType.CENTER, after: 0 })]
                             : [para(t(' '), { after: 1250 })])], [3489, 3489, 3488][i], { borders: cadre(i === 2 ? { style: BorderStyle.DOUBLE, size: 6, color: BRIQUE } : pointille) })) }),
    ],
  });
  return [
    enTete,
    para(t(''), { border: { bottom: { style: BorderStyle.SINGLE, size: 10, color: '222222', space: 1 } }, after: 100 }),
    titre,
    para(t(''), { after: 60 }),
    champ('Client :', rempli.client),
    champ('Adresse / téléphone :'),
    champ('Payé le :'),
    produits,
    para(t(''), { after: 40 }),
    champ("Date d'enlèvement :"),
    champ('Chauffeur (nom et pièce d\'identité) :'),
    champ('Matricule du camion :'),
    bas,
    para(t('Bon au porteur : le chauffeur qui présente ce bon est chargé de la quantité indiquée, une seule fois. Le bon est gardé par l\'usine après le chargement, marqué SERVI, et classé en comptabilité. Tout bon raturé ou sans cachet est nul.', { size: 13, italics: true }), { before: 60, after: 0 }),
  ];
}

const enfants = [];
for (let i = 0; i < nombre; i++) {
  const n = premier + i;
  const rempli = i === 0 ? pre : {};
  if (i % 2 === 1) enfants.push(para(t('✂ - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -', { size: 14, color: '777777' }), { align: AlignmentType.CENTER, before: 140, after: 140 }));
  else if (i > 0) enfants.push(new Paragraph({ children: [new PageBreak()] }));
  enfants.push(...bon(n, rempli));
}
const doc = new Document({
  creator: 'SARL DPR AXXAM', title: "Bons d'enlèvement payés",
  styles: { default: { document: { run: { font: SANS, size: 19 } } } },
  sections: [{ properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 560, bottom: 400, left: 720, right: 720 } } }, children: enfants }],
});
Packer.toBuffer(doc).then(b => { fs.writeFileSync(sortie, b); console.log('écrit', sortie, nombre, 'bons'); });
