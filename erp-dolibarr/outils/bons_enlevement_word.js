// Carnet de bons d'enlèvement payés, au format Word (2 bons par feuille A4).
// Usage : node bons_enlevement_word.js [premier=1] [nombre=10] [fichier.docx]
//         Variables facultatives pour pré-remplir le premier bon (TOUS=1 : tous les bons),
//         PRIX=20.50 : prix TTC unitaire, imprimé seulement sur le bordereau de remise :
//         CLIENT="KERDJA BILEL" ADRESSE="..." TEL="..." PAYE="10/10/2026" PRODUIT=B8 QUANTITE=7040
const fs = require('fs');
const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, BorderStyle,
  AlignmentType, ShadingType, PageBreak, TabStopType, ImageRun, HorizontalPositionRelativeFrom, VerticalPositionRelativeFrom, TextWrappingType } = require('docx');
const path = require('path');
const LOGO = fs.readFileSync(path.join(__dirname, '..', 'modules', 'timbredz', 'img', 'logo-dpr-axxam.png'));
// FILIGRANE=force (« La force de la terre ») ou bien (« Le bien-être dans l'habitat ») : slogan en fond de chaque bon
// DENSITE=moyen ou fort (par défaut) ; images fabriquées par outils/filigranes.py
const DENSITE = process.env.DENSITE === 'moyen' ? 'moyen' : 'fort';
// DIAGONALE=1 : slogan en grande diagonale, du numéro (en haut à droite) jusqu'en bas à gauche
const DIAGONALE = process.env.DIAGONALE === '1';
const FILIGRANE = ['force', 'bien'].includes(process.env.FILIGRANE) ? fs.readFileSync(path.join(__dirname, '..', 'modules', 'timbredz', 'img', `filigrane-${process.env.FILIGRANE}-${DENSITE}${DIAGONALE ? '-diagonale' : ''}.png`)) : null;
const MM = 36000; // EMU par millimètre

const premier = parseInt(process.argv[2] || '1', 10);
const nombre = parseInt(process.argv[3] || '10', 10);
const sortie = process.argv[4] || 'BONS_ENLEVEMENT.docx';
const pre = { client: process.env.CLIENT || '', adresse: [process.env.ADRESSE, process.env.TEL && 'Tél. ' + process.env.TEL].filter(Boolean).join(' — '), paye: process.env.PAYE || '', date: process.env.DATE || process.env.PAYE || '', produit: process.env.PRODUIT || '', quantite: process.env.QUANTITE || '' };

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
const champ = (lib, val) => para([t(lib, { size: 18 }), t(val ? '  ' + val : '  ' + '.'.repeat(Math.max(10, 64 - lib.length)), { mono: true, size: 18, bold: !!val })], { after: 80 });

function bon(n, rempli) {
  const no = String(n).padStart(4, '0');
  const enTete = new Table({
    width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [5200, 5266],
    rows: [new TableRow({ children: [
      cellule([para(new ImageRun({ type: 'png', data: LOGO, transformation: { width: 128, height: 62 }, altText: { title: 'DPR AXXAM', description: 'Logo DPR AXXAM', name: 'logo' } }), { after: 0 }),
               para(t('Le bien-être dans l\'habitat', { italics: true, bold: true, size: 17, color: BRIQUE }), { after: 0 })], 5200, { borders: sans }),
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
      cellule([para(t('N° ' + no, { mono: true, bold: true, size: 30 }), { align: AlignmentType.CENTER, after: 0 }),
               para(t('Date : ' + (rempli.date || '..../..../........'), { mono: true, size: 18, bold: !!rempli.date }), { align: AlignmentType.CENTER, before: 40, after: 0 })], 3000, { va: 'center' }),
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
      new TableRow({ children: ['Cachet de la société et signature du Responsable commercial', 'Visa du magasinier au chargement', 'SERVI — cadre réservé à l\'usine'].map((h, i) =>
        cellule([para(t(h, { size: 15, italics: true, bold: i === 2, color: i === 2 ? BRIQUE : undefined }), { align: AlignmentType.CENTER, after: 0 }),
                 ...(i === 2 ? [para(t('Servi le ..../..../........', { size: 15, mono: true }), { before: 330, align: AlignmentType.CENTER, after: 0 }),
                                para(t('Classé en comptabilité ☐', { size: 15 }), { before: 200, align: AlignmentType.CENTER, after: 0 })]
                             : [para(t(' '), { after: 1000 })])], [3489, 3489, 3488][i], { borders: cadre(i === 2 ? { style: BorderStyle.DOUBLE, size: 6, color: BRIQUE } : pointille) })) }),
    ],
  });
  const fond = FILIGRANE ? [new ImageRun({
    type: 'png', data: FILIGRANE, transformation: DIAGONALE ? { width: 660, height: 412 } : { width: 640, height: 352 },
    floating: { horizontalPosition: { relative: HorizontalPositionRelativeFrom.MARGIN, offset: (DIAGONALE ? 3 : 5) * MM }, verticalPosition: { relative: VerticalPositionRelativeFrom.PARAGRAPH, offset: (DIAGONALE ? 2 : 14) * MM },
      behindDocument: true, allowOverlap: true, wrap: { type: TextWrappingType.NONE } },
    altText: { title: 'Filigrane', description: 'Slogan DPR AXXAM en fond', name: 'filigrane' } })] : [];
  return [
    enTete,
    para([t(''), ...fond], { border: { bottom: { style: BorderStyle.SINGLE, size: 10, color: '222222', space: 1 } }, after: 100 }),
    titre,
    para(t(''), { after: 60 }),
    champ('Client :', rempli.client),
    champ('Adresse / téléphone :', rempli.adresse),
    champ('Payé le :', rempli.paye),
    produits,
    para(t(''), { after: 40 }),
    champ("Date d'enlèvement :"),
    champ('Chauffeur (nom et pièce d\'identité) :'),
    champ('Matricule du camion :'),
    bas,
    para(t('Bon au porteur : le chauffeur qui présente ce bon est chargé de la quantité indiquée, une seule fois. Le bon est gardé par l\'usine après le chargement, marqué SERVI, et classé en comptabilité. Tout bon raturé, sans cachet ou sans signature du Responsable commercial est nul.', { size: 13, italics: true }), { before: 60, after: 0 }),
  ];
}

const enfants = [];
for (let i = 0; i < nombre; i++) {
  const n = premier + i;
  const rempli = (i === 0 || process.env.TOUS === '1') ? pre : {};
  if (i % 2 === 1) enfants.push(para(t('✂ - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -', { size: 14, color: '777777' }), { align: AlignmentType.CENTER, before: 140, after: 140 }));
  else if (i > 0) enfants.push(new Paragraph({ children: [new PageBreak()] }));
  enfants.push(...bon(n, rempli));
}
if (process.env.TOUS === '1' && pre.client) {
  const qte = Number(pre.quantite || 0), prix = Number(process.env.PRIX || 0);
  const fmt = (v, d = 0) => v.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[\u202f\u00a0]/g, ' ');
  const n1 = String(premier).padStart(4, '0'), n2 = String(premier + nombre - 1).padStart(4, '0');
  const ligne = (a, b, gras) => new TableRow({ children: [cellule([para(t(a, { size: 19 }), { after: 0 })], 5200), cellule([para(t(b, { mono: true, size: 19, bold: gras }), { align: AlignmentType.RIGHT, after: 0 })], 5266)] });
  enfants.push(new Paragraph({ children: [new PageBreak()] }));
  enfants.push(new Table({ width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [5200, 5266], rows: [new TableRow({ children: [
    cellule([para(new ImageRun({ type: 'png', data: LOGO, transformation: { width: 128, height: 62 }, altText: { title: 'DPR AXXAM', description: 'Logo DPR AXXAM', name: 'logo' } }), { after: 0 })], 5200, { borders: sans }),
    cellule([para(t('SARL DPR AXXAM · ZAC Helouane, Ighzer Amokrane (Béjaïa)', { size: 14 }), { align: AlignmentType.RIGHT, after: 0 }), para(t('RC 08B0185858-06/00 · NIF 000806018585831', { size: 14 }), { align: AlignmentType.RIGHT, after: 0 })], 5266, { borders: sans, va: 'bottom' }),
  ] })] }));
  enfants.push(para(t(''), { border: { bottom: { style: BorderStyle.SINGLE, size: 10, color: '222222', space: 1 } }, after: 300 }));
  enfants.push(para(t("BORDEREAU DE REMISE DE BONS D'ENLÈVEMENT PAYÉS", { mono: true, bold: true, italics: true, size: 26 }), { align: AlignmentType.CENTER, after: 300 }));
  enfants.push(new Table({ width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [5200, 5266], rows: [
    ligne('Client', pre.client, true),
    ligne('Adresse / téléphone', pre.adresse || ''),
    ligne('Date de remise et de règlement', pre.paye || ''),
    ligne('Bons remis', `N° ${n1} à N° ${n2}  (${nombre} bons)`, true),
    ligne('Produit', ({ B8: 'Brique creuse 8 trous (B8)', B12: 'Brique creuse 12 trous (B12)', HOURDIS: 'Hourdis (entrevous) 16' }[pre.produit] || pre.produit)),
    ligne('Quantité par bon', fmt(qte) + ' pièces'),
    ligne('Quantité totale', fmt(qte * nombre) + ' pièces', true),
    ...(prix ? [ligne('Prix unitaire TTC', fmt(prix, 2) + ' DA'), ligne('Montant par bon (TTC)', fmt(qte * prix, 2) + ' DA'), ligne('MONTANT TOTAL RÉGLÉ (TTC)', fmt(qte * prix * nombre, 2) + ' DA', true)] : []),
  ] }));
  enfants.push(para(t(`Le client reconnaît avoir reçu les ${nombre} bons d'enlèvement payés N° ${n1} à N° ${n2}. Chaque bon donne droit à un seul enlèvement de la quantité indiquée, à l'usine de la ZAC Helouane. Les bons servis sont gardés par l'usine, marqués SERVI et classés en comptabilité avec ce bordereau.`, { size: 18 }), { before: 300, after: 400 }));
  enfants.push(new Table({ width: { size: LARGEUR, type: WidthType.DXA }, columnWidths: [5233, 5233], rows: [new TableRow({ children: ['Cachet de la société et signature du Responsable commercial', 'Le client (reçu les bons ci-dessus)'].map(h =>
    cellule([para(t(h, { size: 16, italics: true }), { align: AlignmentType.CENTER, after: 0 }), para(t(' '), { after: 1600 })], 5233, { borders: cadre(pointille) })) })] }));
  enfants.push(para(t('Suivi des enlèvements (à remplir par l\'usine) :', { size: 17, bold: true }), { before: 300, after: 100 }));
  const cols = 10, rangs = Math.ceil(nombre / cols), wc = Math.floor(LARGEUR / cols);
  enfants.push(new Table({ width: { size: wc * cols, type: WidthType.DXA }, columnWidths: Array(cols).fill(wc), rows: Array.from({ length: rangs }, (_, r) => new TableRow({ children: Array.from({ length: cols }, (_, c) => {
    const k = r * cols + c; const n = premier + k;
    return cellule(k < nombre ? [para(t(String(n).padStart(4, '0'), { mono: true, size: 15, bold: true }), { align: AlignmentType.CENTER, after: 0 }), para(t('servi le', { size: 11, color: '777777' }), { align: AlignmentType.CENTER, after: 220 })] : [para(t(''), { after: 0 })], wc);
  }) })) }));
}

const doc = new Document({
  creator: 'SARL DPR AXXAM', title: "Bons d'enlèvement payés",
  styles: { default: { document: { run: { font: SANS, size: 19 } } } },
  sections: [{ properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 560, bottom: 400, left: 720, right: 720 } } }, children: enfants }],
});
Packer.toBuffer(doc).then(b => { fs.writeFileSync(sortie, b); console.log('écrit', sortie, nombre, 'bons'); });
