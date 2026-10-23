<?php
/*
 * GMAO DPR AXXAM — interface (une page, utilisable sur téléphone).
 * Accès : menu « Maintenance » de l'ERP, ou directement http://serveur:8080/custom/gmao/index.php
 */
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $f) {
	if (!$res && file_exists(__DIR__.'/'.$f)) $res = @include __DIR__.'/'.$f;
}
if (!$res) die('main.inc.php introuvable');
if (!$user->admin && !$user->hasRight('gmao', 'lire')) accessforbidden();
$jeton = newToken();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance DPR AXXAM</title>
<style>
:root{--brique:#b4472b;--brique-f:#7d2a17;--encre:#231a16;--gris:#6b5a51;--ligne:#e3d6ca;--fond:#f6f1ec;--carte:#fff;--ok:#2e7d4f;--alerte:#c77700;--panne:#c62828;--bleu:#2f6fbf}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--encre:#f1e7de;--gris:#bba99c;--ligne:#3b2d27;--fond:#17110e;--carte:#231a16}}
*{box-sizing:border-box}
body{margin:0;font-family:"DejaVu Sans",Arial,sans-serif;font-size:15px;background:var(--fond);color:var(--encre)}
a{color:var(--bleu)}
header{position:sticky;top:0;z-index:20;background:#2a1d17;color:#fff;display:flex;align-items:center;gap:12px;padding:8px 14px;flex-wrap:wrap}
header img{height:34px;background:#fff;border-radius:6px;padding:2px 6px}
header h1{font-size:1.05rem;margin:0;flex:1;min-width:150px}
header a{color:#f3c9b8;font-size:.85rem;text-decoration:none}
nav{display:flex;gap:4px;overflow-x:auto;background:#3a2a22;padding:0 10px;position:sticky;top:50px;z-index:19}
nav button{flex:0 0 auto;background:none;border:0;color:#e9d9cd;padding:11px 12px;font:inherit;cursor:pointer;border-bottom:3px solid transparent}
nav button[aria-current="true"]{color:#fff;border-bottom-color:var(--brique)}
main{max-width:1200px;margin:0 auto;padding:14px}
.grand{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.btn{font:inherit;border:1px solid var(--brique);background:var(--carte);color:var(--brique);border-radius:9px;padding:9px 14px;cursor:pointer}
.btn.plein{background:var(--brique);color:#fff}
.btn.panne{background:var(--panne);border-color:var(--panne);color:#fff;font-weight:700;font-size:1.05rem;padding:13px 20px}
.btn.petit{padding:5px 10px;font-size:.85rem}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.kpi{background:var(--carte);border:1px solid var(--ligne);border-radius:12px;padding:12px}
.kpi b{display:block;font-size:1.7rem}
.kpi span{color:var(--gris);font-size:.85rem}
.kpi.rouge b{color:var(--panne)}.kpi.orange b{color:var(--alerte)}.kpi.vert b{color:var(--ok)}
.deux{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:14px}
.carte{background:var(--carte);border:1px solid var(--ligne);border-radius:12px;padding:14px;min-width:0}
.carte h2{font-size:1.05rem;margin:0 0 10px}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:7px 6px;border-bottom:1px solid var(--ligne);vertical-align:top}
th{color:var(--gris);font-weight:600;font-size:.8rem}
.defile{overflow-x:auto}
.recherche{width:100%;font:inherit;font-size:1.05rem;padding:12px 14px;border:2px solid var(--brique);border-radius:10px;background:var(--carte);color:var(--encre);margin-bottom:12px}
.arbre{list-style:none;margin:0;padding:0}
.arbre ul{list-style:none;margin:0;padding-left:18px;border-left:1px dashed var(--ligne)}
.noeud{display:flex;align-items:center;gap:8px;padding:5px 4px;border-radius:7px;cursor:pointer}
.noeud:hover{background:rgba(180,71,43,.08)}
.pli{width:18px;text-align:center;color:var(--gris);flex:0 0 18px}
.pastille{width:10px;height:10px;border-radius:50%;flex:0 0 10px;background:var(--ok)}
.pastille.panne{background:var(--panne);box-shadow:0 0 0 3px rgba(198,40,40,.25)}.pastille.arret{background:#999}.pastille.rebut{background:#444}
.tag{font-family:"DejaVu Sans Mono",monospace;font-size:.78rem;color:var(--gris)}
.badge{display:inline-block;font-size:.72rem;padding:1px 7px;border-radius:999px;background:var(--ligne);color:var(--encre);white-space:nowrap}
.badge.A{background:#fde1dc;color:#8a1f12}.badge.retard{background:#fde1dc;color:#8a1f12}.badge.bientot{background:#fff1d6;color:#7a4b00}
.badge.panne{background:var(--panne);color:#fff}.badge.ouverte{background:#fde1dc;color:#8a1f12}.badge.en_cours{background:#fff1d6;color:#7a4b00}.badge.cloturee{background:#dcefe3;color:#1d5a37}
.fiche dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 12px;margin:0 0 12px}
.fiche dt{color:var(--gris);font-size:.85rem}.fiche dd{margin:0}
.repere{font-family:"DejaVu Sans Mono",monospace;background:#fff6c7;color:#3d3000;padding:0 4px;border-radius:4px}
.vide{color:var(--gris);font-style:italic}
dialog{border:0;border-radius:14px;padding:0;width:min(640px,96vw);max-height:92vh;background:var(--carte);color:var(--encre)}
dialog::backdrop{background:rgba(0,0,0,.5)}
dialog form{padding:16px;display:grid;gap:10px}
dialog h3{margin:0}
.champs{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.champs .large{grid-column:1/-1}
label{display:grid;gap:3px;font-size:.82rem;color:var(--gris)}
input,select,textarea{font:inherit;font-size:.95rem;padding:8px 9px;border:1px solid var(--ligne);border-radius:8px;background:var(--fond);color:var(--encre);min-width:0;width:100%}
textarea{min-height:70px}
.actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.message{position:fixed;bottom:16px;left:50%;transform:translateX(-50%);background:#2a1d17;color:#fff;padding:10px 16px;border-radius:10px;z-index:50;opacity:0;transition:opacity .3s;pointer-events:none}
.message.voir{opacity:1}
.check li{margin:2px 0}
@media (max-width:640px){.champs{grid-template-columns:1fr}main{padding:10px}header img{height:28px}nav{top:46px}}
@media print{header,nav,.btn{display:none}}
</style>
</head>
<body>
<header>
  <img src="../timbredz/img/logo-dpr-axxam.png" alt="DPR AXXAM">
  <h1>Maintenance de l'usine</h1>
  <span id="qui" style="font-size:.85rem;opacity:.85"></span>
  <a href="<?php echo DOL_URL_ROOT; ?>/index.php">← ERP</a>
</header>
<nav id="onglets">
  <button data-vue="accueil" aria-current="true">Tableau de bord</button>
  <button data-vue="usine">Machines et moteurs</button>
  <button data-vue="preventif">Préventif</button>
  <button data-vue="pieces">Pièces</button>
  <button data-vue="alarmes">Alarmes automate</button>
  <button data-vue="journal">Journal</button>
  <button data-vue="import" data-gerer>Import</button>
</nav>
<main id="vue"></main>
<div class="message" id="message" role="status"></div>

<dialog id="dlg"><form id="dlgForm" method="dialog"></form></dialog>

<script>
const JETON0 = <?php echo json_encode($jeton); ?>;
const API = 'api.php';
let D = null, jeton = JETON0, vue = 'accueil', selection = null, ouverts = new Set(), filtre = '';
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const jour = d => d ? String(d).slice(0,10).split('-').reverse().join('/') : '';
const heure = d => d ? jour(d) + ' ' + String(d).slice(11,16) : '';
const auj = () => new Date().toISOString().slice(0,10);
const maintenant = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0,16); };
function message(t){ const m = $('#message'); m.textContent = t; m.classList.add('voir'); setTimeout(() => m.classList.remove('voir'), 2600); }

async function charger(){
  const r = await fetch(API + '?op=tout', {credentials:'same-origin'});
  if (!r.ok) { $('#vue').innerHTML = '<p class="carte">Erreur de chargement (' + r.status + '). Reconnectez-vous à l\'ERP.</p>'; return; }
  D = await r.json(); jeton = D.jeton || jeton;
  D.parId = new Map(D.equipements.map(e => [String(e.id), e]));
  D.enfants = new Map();
  D.equipements.forEach(e => { const p = String(e.parent || ''); if (!D.enfants.has(p)) D.enfants.set(p, []); D.enfants.get(p).push(e); });
  $('#qui').textContent = D.utilisateur;
  document.querySelectorAll('[data-gerer]').forEach(b => b.hidden = !D.droits.gerer);
  afficher();
}
async function envoyer(action, donnees){
  const f = new FormData();
  f.append('op', action); f.append('token', jeton);
  for (const k in donnees) f.append(k, donnees[k] ?? '');
  const r = await fetch(API, {method:'POST', body:f, credentials:'same-origin'});
  let j = {}; try { j = await r.json(); } catch(e) { j = {erreur: 'Réponse invalide du serveur (' + r.status + ')'}; }
  if (!r.ok || j.erreur) { alert(j.erreur || ('Erreur ' + r.status)); throw new Error(j.erreur); }
  return j;
}
const nomEq = id => { const e = D.parId.get(String(id)); return e ? e.nom : '—'; };
function chemin(id){ const t = []; let e = D.parId.get(String(id)); let n = 0; while (e && n++ < 20) { t.unshift(e.nom); e = D.parId.get(String(e.parent || '')); } return t.join(' › '); }
function machineDe(id){ let e = D.parId.get(String(id)); let n = 0; while (e && !['machine','armoire'].includes(e.type) && e.parent && n++ < 20) e = D.parId.get(String(e.parent)); return e; }
function etatPlan(p){ const j = Math.round((new Date(p.prochaine) - new Date(auj())) / 864e5); return j < 0 ? ['retard', `en retard de ${-j} j`] : j <= 2 ? ['bientot', j === 0 ? "aujourd'hui" : `dans ${j} j`] : ['', `le ${jour(p.prochaine)}`]; }

// ------------------------------------------------------------------ vues
function afficher(){
  document.querySelectorAll('#onglets button').forEach(b => b.setAttribute('aria-current', b.dataset.vue === vue));
  ({accueil: vAccueil, usine: vUsine, preventif: vPreventif, pieces: vPieces, alarmes: vAlarmes, journal: vJournal, import: vImport})[vue]();
}
function boutonPanne(){ return D.droits.ecrire ? '<button class="btn panne" onclick="fPanne()">⚠ Déclarer une panne</button>' : ''; }

function vAccueil(){
  const enPanne = D.equipements.filter(e => e.statut === 'panne');
  const retard = D.plans.filter(p => +p.actif && etatPlan(p)[0] === 'retard');
  const sousSeuil = D.pieces.filter(p => +p.seuil > 0 && +p.stock <= +p.seuil);
  const aFaire = D.plans.filter(p => +p.actif && etatPlan(p)[0] !== '').slice(0, 12);
  $('#vue').innerHTML = `
  <div class="grand">${boutonPanne()}</div>
  <div class="kpis">
    <div class="kpi ${enPanne.length ? 'rouge' : 'vert'}"><b>${enPanne.length}</b><span>machine(s) en panne</span></div>
    <div class="kpi"><b>${D.stats30.pannes}</b><span>pannes sur 30 jours</span></div>
    <div class="kpi"><b>${(D.stats30.arret_min / 60).toFixed(1).replace('.', ',')} h</b><span>d'arrêt sur 30 jours</span></div>
    <div class="kpi ${retard.length ? 'orange' : 'vert'}"><b>${retard.length}</b><span>préventif(s) en retard</span></div>
    <div class="kpi ${sousSeuil.length ? 'orange' : 'vert'}"><b>${sousSeuil.length}</b><span>pièce(s) sous le seuil</span></div>
    <div class="kpi" title="Durée moyenne d'une panne, 90 derniers jours"><b>${+D.fiab.n ? Math.round(D.fiab.mttr_min) + ' min' : '—'}</b><span>MTTR (réparation moyenne)</span></div>
    <div class="kpi" title="Temps moyen de bon fonctionnement entre deux pannes d'une machine, 90 derniers jours"><b>${D.fiab.mtbf_h ? String(D.fiab.mtbf_h).replace('.', ',') + ' h' : '—'}</b><span>MTBF (entre deux pannes)</span></div>
    <div class="kpi"><b>${D.fiab.preventif_30j}</b><span>préventifs faits sur 30 jours</span></div>
  </div>
  <div class="deux">
    <section class="carte"><h2>Interventions en cours</h2>${D.ouvertes.length ? `<div class="defile"><table><tr><th>Depuis</th><th>Machine</th><th>Symptôme</th><th></th></tr>
      ${D.ouvertes.map(i => `<tr><td>${heure(i.date_debut)}</td><td><a href="#" onclick="ouvrirFiche(${i.equipement});return false">${esc(nomEq(i.equipement))}</a></td><td>${esc(i.symptome || '')}${i.code_alarme ? ` <span class="repere">${esc(i.code_alarme)}</span>` : ''}</td>
      <td><span class="badge ${i.statut}">${i.statut.replace('_', ' ')}</span> ${D.droits.ecrire ? `<button class="btn petit" onclick="fCloture(${i.id})">Clôturer</button>` : ''}</td></tr>`).join('')}</table></div>` : '<p class="vide">Aucune intervention en cours.</p>'}</section>
    <section class="carte"><h2>Préventif à faire</h2>${aFaire.length ? `<table>${aFaire.map(p => { const [c, t] = etatPlan(p); return `<tr><td>${esc(p.nom)}<br><span class="tag">${esc(nomEq(p.equipement))}</span></td><td><span class="badge ${c}">${t}</span></td></tr>`; }).join('')}</table>` : '<p class="vide">Rien d\'urgent.</p>'}</section>
    <section class="carte"><h2>Machines qui arrêtent le plus (90 jours)</h2>${D.top90.length ? `<table><tr><th>Machine</th><th>Pannes</th><th>Arrêt</th></tr>${D.top90.map(t => `<tr><td><a href="#" onclick="ouvrirFiche(${t.equipement});return false">${esc(nomEq(t.equipement))}</a></td><td>${t.pannes}</td><td>${(t.arret_min / 60).toFixed(1).replace('.', ',')} h</td></tr>`).join('')}</table>` : '<p class="vide">Pas encore de panne enregistrée.</p>'}</section>
    <section class="carte"><h2>Pièces à commander</h2>${sousSeuil.length ? `<table>${sousSeuil.map(p => `<tr><td>${esc(p.nom)}<br><span class="tag">${esc(p.reference || '')}</span></td><td>${+p.stock} / seuil ${+p.seuil} ${esc(p.unite || '')}</td></tr>`).join('')}</table>` : '<p class="vide">Stock suffisant.</p>'}</section>
  </div>`;
}

function correspond(e, q){
  if (!q) return true;
  const al = D.alarmes.filter(a => String(a.equipement) === String(e.id)).map(a => a.code + ' ' + a.texte).join(' ');
  return [e.tag, e.nom, e.repere, e.armoire, e.folio, e.fabricant, e.modele, al].join(' ').toLowerCase().includes(q);
}
function vUsine(){
  $('#vue').innerHTML = `
  <input class="recherche" id="q" type="search" placeholder="Chercher : nom, tag, repère (QM051, KM12, DP57…), armoire, folio, code alarme" value="${esc(filtre)}" autocomplete="off">
  <div class="grand">${boutonPanne()}${D.droits.gerer ? '<button class="btn" onclick="fEquipement()">+ Machine / moteur</button>' : ''}<button class="btn" onclick="toutDeplier(true)">Tout déplier</button><button class="btn" onclick="toutDeplier(false)">Replier</button></div>
  <div class="deux"><section class="carte defile"><ul class="arbre" id="arbre"></ul></section><section class="carte fiche" id="fiche"><p class="vide">Choisissez une machine ou un moteur.</p></section></div>`;
  $('#q').addEventListener('input', e => { filtre = e.target.value; dessinerArbre(); });
  dessinerArbre();
  if (selection) ouvrirFiche(selection, false);
}
function dessinerArbre(){
  const q = filtre.trim().toLowerCase();
  const garde = new Set();
  if (q) D.equipements.forEach(e => { if (correspond(e, q)) { let x = e, n = 0; while (x && n++ < 30) { garde.add(String(x.id)); x = D.parId.get(String(x.parent || '')); } } });
  const branche = p => (D.enfants.get(p) || []).filter(e => !q || garde.has(String(e.id))).map(e => {
    const id = String(e.id), enf = (D.enfants.get(id) || []).length, ouvert = q || ouverts.has(id);
    return `<li><div class="noeud" onclick="clicNoeud(${e.id})"><span class="pli">${enf ? (ouvert ? '▾' : '▸') : ''}</span><span class="pastille ${e.statut}" title="${esc(e.statut)}"></span>
      <span>${esc(e.nom)} <span class="tag">${esc(e.tag)}</span>${e.repere ? ` <span class="repere">${esc(e.repere)}</span>` : ''}</span>${e.criticite === 'A' ? ' <span class="badge A">A</span>' : ''}</div>
      ${enf && ouvert ? `<ul>${branche(id)}</ul>` : ''}</li>`;
  }).join('');
  const html = branche('');
  $('#arbre').innerHTML = html || '<li class="vide">Aucun résultat.</li>';
}
function clicNoeud(id){ id = String(id); if (ouverts.has(id)) ouverts.delete(id); else ouverts.add(id); dessinerArbre(); ouvrirFiche(id); }
function toutDeplier(o){ ouverts = o ? new Set(D.equipements.map(e => String(e.id))) : new Set(); dessinerArbre(); }

async function ouvrirFiche(id, basculer = true){
  if (vue !== 'usine') { vue = 'usine'; selection = String(id); afficher(); return; }
  selection = String(id);
  const e = D.parId.get(String(id)); if (!e) return;
  const fiche = $('#fiche');
  const enfants = D.enfants.get(String(id)) || [];
  const plans = D.plans.filter(p => String(p.equipement) === String(id));
  const pieces = D.pieces.filter(p => String(p.equipement) === String(id));
  const alarmes = D.alarmes.filter(a => String(a.equipement) === String(id));
  fiche.innerHTML = `<h2>${esc(e.nom)}</h2><p class="tag">${esc(chemin(id))}</p>
    <div class="grand">${D.droits.ecrire ? `<button class="btn panne" onclick="fPanne(${e.id})">⚠ Panne sur cet équipement</button>` : ''}${D.droits.gerer ? `<button class="btn" onclick="fEquipement(${e.id})">Modifier</button><button class="btn" onclick="fEquipement(null, ${e.id})">+ Sous-ensemble</button><button class="btn" onclick="fPlan(null, ${e.id})">+ Plan préventif</button>` : ''}</div>
    <dl>
      <dt>Tag</dt><dd class="tag">${esc(e.tag)}</dd>
      <dt>Type</dt><dd>${esc(e.type)} · criticité <span class="badge ${e.criticite}">${esc(e.criticite)}</span> · <span class="badge ${e.statut === 'panne' ? 'panne' : ''}">${esc(e.statut)}</span></dd>
      ${e.armoire ? `<dt>Armoire</dt><dd>${esc(e.armoire)}</dd>` : ''}
      ${e.repere ? `<dt>Repères schéma</dt><dd><span class="repere">${esc(e.repere)}</span></dd>` : ''}
      ${e.folio ? `<dt>Folio schéma</dt><dd>${esc(e.folio)}</dd>` : ''}
      ${e.puissance_kw ? `<dt>Puissance</dt><dd>${String(+e.puissance_kw).replace('.', ',')} kW${e.vitesse ? ' · ' + esc(e.vitesse) : ''}</dd>` : ''}
      ${e.fabricant || e.modele ? `<dt>Fabricant</dt><dd>${esc(e.fabricant || '')} ${esc(e.modele || '')} ${e.numserie ? '· n° ' + esc(e.numserie) : ''}</dd>` : ''}
      ${e.notes ? `<dt>Notes</dt><dd style="white-space:pre-wrap">${esc(e.notes)}</dd>` : ''}
    </dl>
    ${enfants.length ? `<h3>Sous-ensembles</h3><table>${enfants.map(c => `<tr><td><span class="pastille ${c.statut}" style="display:inline-block"></span> <a href="#" onclick="ouvrirFiche(${c.id});return false">${esc(c.nom)}</a></td><td>${c.repere ? `<span class="repere">${esc(c.repere)}</span>` : ''}</td><td>${c.puissance_kw ? String(+c.puissance_kw).replace('.', ',') + ' kW' : ''}</td></tr>`).join('')}</table>` : ''}
    ${alarmes.length ? `<h3>Alarmes automate</h3><table>${alarmes.map(a => `<tr><td><span class="repere">${esc(a.code)}</span></td><td>${esc(a.texte)}${a.remede ? `<br><small>→ ${esc(a.remede)}</small>` : ''}</td></tr>`).join('')}</table>` : ''}
    ${plans.length ? `<h3>Préventif</h3><table>${plans.map(p => { const [c, t] = etatPlan(p); return `<tr><td><a href="#" onclick="fPlanVoir(${p.id});return false">${esc(p.nom)}</a></td><td><span class="badge ${c}">${t}</span></td></tr>`; }).join('')}</table>` : ''}
    ${pieces.length ? `<h3>Pièces</h3><table>${pieces.map(p => `<tr><td>${esc(p.nom)}</td><td>${+p.stock} ${esc(p.unite || '')}</td></tr>`).join('')}</table>` : ''}
    <h3>Historique</h3><div id="histo" class="defile"><p class="vide">Chargement…</p></div>`;
  const r = await fetch(API + '?op=historique&id=' + encodeURIComponent(id), {credentials:'same-origin'});
  const h = await r.json();
  $('#histo').innerHTML = h.length ? `<table><tr><th>Date</th><th>Type</th><th>Détail</th><th>Arrêt</th></tr>${h.map(i => `<tr><td>${heure(i.date_debut)}</td><td><span class="badge ${i.statut}">${esc(i.type)}</span></td>
    <td>${esc(i.symptome || '')}${i.cause ? `<br><small>Cause : ${esc(i.cause)}</small>` : ''}${i.action ? `<br><small>Action : ${esc(i.action)}</small>` : ''}${i.pieces ? `<br><small>Pièces : ${esc(i.pieces)}</small>` : ''}${i.technicien ? `<br><small>${esc(i.technicien)}</small>` : ''}</td>
    <td>${i.arret_min ? i.arret_min + ' min' : ''}</td></tr>`).join('')}</table>` : '<p class="vide">Aucune intervention enregistrée.</p>';
  if (basculer && window.innerWidth < 900) fiche.scrollIntoView({behavior:'smooth'});
}

function vPreventif(){
  const plans = D.plans.filter(p => +p.actif);
  $('#vue').innerHTML = `<div class="grand">${D.droits.gerer ? '<button class="btn" onclick="fPlan()">+ Plan préventif</button>' : ''}</div>
  <section class="carte defile"><table><tr><th>Échéance</th><th>Plan</th><th>Machine</th><th>Fréquence</th><th>Dernière fois</th><th></th></tr>
  ${plans.map(p => { const [c, t] = etatPlan(p); return `<tr><td><span class="badge ${c}">${t}</span></td><td><a href="#" onclick="fPlanVoir(${p.id});return false">${esc(p.nom)}</a></td>
  <td>${esc(nomEq(p.equipement))}</td><td>${p.frequence_j} j</td><td>${jour(p.derniere) || '—'}</td><td>${D.droits.ecrire ? `<button class="btn petit plein" onclick="fPlanVoir(${p.id})">Faire</button>` : ''}</td></tr>`; }).join('') || '<tr><td colspan="6" class="vide">Aucun plan.</td></tr>'}</table></section>`;
}
function vPieces(){
  $('#vue').innerHTML = `<input class="recherche" id="qp" type="search" placeholder="Chercher une pièce : nom, référence, machine"><div class="grand">${D.droits.gerer ? '<button class="btn" onclick="fPiece()">+ Pièce</button>' : ''}</div>
  <section class="carte defile"><table><tr><th>Pièce</th><th>Référence</th><th>Machine</th><th>Stock</th><th>Seuil</th><th>Casier</th><th></th></tr>
  ${D.pieces.map(p => `<tr data-q="${esc((p.nom + ' ' + (p.reference || '') + ' ' + (p.equipement ? nomEq(p.equipement) : '')).toLowerCase())}"><td>${esc(p.nom)}</td><td class="tag">${esc(p.reference || '')}</td><td>${p.equipement ? esc(nomEq(p.equipement)) : ''}</td>
  <td><b style="color:${+p.seuil > 0 && +p.stock <= +p.seuil ? 'var(--panne)' : 'inherit'}">${+p.stock}</b> ${esc(p.unite || '')}</td><td>${+p.seuil}</td><td>${esc(p.emplacement || '')}</td>
  <td style="white-space:nowrap">${D.droits.ecrire ? `<button class="btn petit" onclick="mvt(${p.id},-1)">− sortie</button> <button class="btn petit" onclick="mvt(${p.id},1)">+ entrée</button>` : ''}${D.droits.gerer ? ` <button class="btn petit" onclick="fPiece(${p.id})">✎</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="7" class="vide">Aucune pièce.</td></tr>'}</table></section>`;
  $('#qp').addEventListener('input', e => { const q = e.target.value.trim().toLowerCase(); document.querySelectorAll('tr[data-q]').forEach(tr => tr.hidden = q && !tr.dataset.q.includes(q)); });
}
function vAlarmes(){
  $('#vue').innerHTML = `<input class="recherche" id="qa" type="search" placeholder="Code ou texte de l'alarme affichée sur l'écran">
  <div class="grand">${D.droits.gerer ? '<button class="btn" onclick="fAlarme()">+ Alarme</button>' : ''}</div><section class="carte defile" id="listeAl"></section>`;
  const dessiner = () => { const q = $('#qa').value.trim().toLowerCase();
    const l = D.alarmes.filter(a => !q || (a.code + ' ' + a.texte).toLowerCase().includes(q)).slice(0, 300);
    $('#listeAl').innerHTML = `<table><tr><th>Code</th><th>Alarme</th><th>Machine</th><th>Que faire</th><th></th></tr>${l.map(a => `<tr><td><span class="repere">${esc(a.code)}</span></td><td>${esc(a.texte)}</td>
      <td>${a.equipement ? `<a href="#" onclick="ouvrirFiche(${a.equipement});return false">${esc(nomEq(a.equipement))}</a>` : ''}</td><td>${a.causes ? `<small>Causes : ${esc(a.causes)}</small><br>` : ''}${esc(a.remede || '')}</td>
      <td style="white-space:nowrap">${D.droits.ecrire && a.equipement ? `<button class="btn petit" onclick="fPanne(${a.equipement}, ${JSON.stringify(a.code).replace(/"/g, '&quot;')})">Panne</button>` : ''}${D.droits.gerer ? ` <button class="btn petit" onclick="fAlarme(${a.id})">✎</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="vide">Aucune alarme. Importez la liste depuis le programme automate (onglet Import).</td></tr>'}</table>`; };
  $('#qa').addEventListener('input', dessiner); dessiner();
}
function vJournal(){
  $('#vue').innerHTML = `<section class="carte defile"><h2>30 dernières interventions</h2><table><tr><th>Date</th><th>Machine</th><th>Type</th><th>Détail</th><th>Arrêt</th><th></th></tr>
  ${D.recentes.map(i => `<tr><td>${heure(i.date_debut)}</td><td><a href="#" onclick="ouvrirFiche(${i.equipement});return false">${esc(nomEq(i.equipement))}</a></td><td><span class="badge ${i.statut}">${esc(i.type)}</span></td>
  <td>${esc(i.symptome || i.action || '')}${i.cause ? `<br><small>Cause : ${esc(i.cause)}</small>` : ''}</td><td>${i.arret_min ? i.arret_min + ' min' : ''}</td><td>${D.droits.ecrire && i.statut !== 'cloturee' ? `<button class="btn petit" onclick="fCloture(${i.id})">Clôturer</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6" class="vide">Aucune intervention.</td></tr>'}</table></section>`;
}
function vImport(){
  $('#vue').innerHTML = `<div class="deux">
  <section class="carte"><h2>Machines et moteurs (schéma électrique)</h2>
    <p>Une ligne par machine, moteur ou armoire, avec la première ligne d'en-têtes. Séparateur <b>;</b> ou tabulation : un copier-coller depuis Excel fonctionne. Un tag déjà présent est <b>mis à jour</b>, sans créer de doublon.</p>
    <p class="tag">tag;nom;type;parent;armoire;repere;puissance_kw;vitesse;folio;criticite;fabricant;modele;notes</p>
    <p class="tag">DPR-PRE-LAM-02-MOT;Moteur laminoir finisseur;moteur;DPR-PRE-LAM-02;ARM-PRE;QM051 KM051 DP57;45;1480 tr/min;=PRE/12;A;Siemens;1LE1;</p>
    <textarea id="csvEq" rows="10" placeholder="Collez ici"></textarea><div class="actions"><button class="btn plein" onclick="importer('equipements','csvEq')">Importer</button></div></section>
  <section class="carte"><h2>Alarmes de l'automate</h2>
    <p>Export de la liste des messages du programme (WinCC / pupitre) : une alarme par ligne.</p>
    <p class="tag">code;texte;tag;causes;remede</p>
    <p class="tag">A039;Ventilatore assiale mandata linea B allarme da inverter;DPR-SEC-VENT-B;Défaut variateur;Lire le code défaut du variateur, réarmer</p>
    <textarea id="csvAl" rows="10" placeholder="Collez ici"></textarea><div class="actions"><button class="btn plein" onclick="importer('alarmes','csvAl')">Importer</button></div></section>
  <section class="carte"><h2>Pièces de rechange (catalogues constructeur)</h2>
    <p>Une pièce par ligne. Une référence déjà présente est mise à jour, sans toucher au stock.</p>
    <p class="tag">nom;reference;tag;unite;stock;seuil;emplacement;fournisseur;cout</p>
    <p class="tag">Lame de soudage;M3 11001;DPR-CND-CER-01-TETE;pièce;2;1;Casier C4;Messersì;</p>
    <textarea id="csvPi" rows="10" placeholder="Collez ici"></textarea><div class="actions"><button class="btn plein" onclick="importer('pieces','csvPi')">Importer</button></div></section></div>`;
}
async function importer(quoi, champ){
  const r = await envoyer('import', {quoi, csv: $('#' + champ).value});
  alert(`${r.crees} créé(s), ${r.mis_a_jour} mis à jour.` + (r.erreurs.length ? `\n\n${r.erreurs.length} problème(s) :\n` + r.erreurs.slice(0, 15).join('\n') : ''));
  await charger();
}
async function mvt(id, sens){
  const q = prompt(sens > 0 ? 'Quantité entrée en stock :' : 'Quantité sortie du stock :', '1'); if (!q) return;
  await envoyer('piece_mvt', {id, quantite: sens * parseFloat(q.replace(',', '.'))}); message('Stock mis à jour'); await charger();
}

// ------------------------------------------------------------------ formulaires
function optionsEq(sel, filtreType){
  const tri = [...D.equipements].sort((a, b) => chemin(a.id).localeCompare(chemin(b.id), 'fr'));
  return '<option value="">—</option>' + tri.filter(e => !filtreType || filtreType.includes(e.type)).map(e => `<option value="${e.id}" ${String(sel) === String(e.id) ? 'selected' : ''}>${esc(chemin(e.id))}</option>`).join('');
}
function dialogue(html, envoi){
  const d = $('#dlg'), f = $('#dlgForm');
  f.innerHTML = html + `<div class="actions"><button type="button" class="btn" id="dlgAnnuler">Annuler</button><button class="btn plein" value="ok">Enregistrer</button></div>`;
  $('#dlgAnnuler').onclick = () => d.close();
  f.onsubmit = async ev => { ev.preventDefault(); const v = Object.fromEntries(new FormData(f)); try { await envoi(v); d.close(); await charger(); } catch (e) {} };
  d.showModal();
}
function fPanne(eq, alarme){
  dialogue(`<h3>Déclarer une panne</h3><div class="champs">
    <label class="large">Machine ou moteur<select name="equipement" required>${optionsEq(eq)}</select></label>
    <label>Début de l'arrêt<input type="datetime-local" name="date_debut" value="${maintenant()}"></label>
    <label>Code alarme<input name="code_alarme" value="${esc(alarme || '')}"></label>
    <label class="large">Symptôme (ce qu'on constate)<textarea name="symptome" required></textarea></label>
    <label>Technicien<input name="technicien" value="${esc(D.utilisateur)}"></label>
    <label>Statut<select name="statut"><option value="ouverte">Ouverte</option><option value="en_cours">En cours</option></select></label></div>`,
    v => envoyer('intervention', {...v, type: 'panne'}).then(() => message('Panne enregistrée')));
}
function fCloture(id){
  const i = D.ouvertes.find(x => x.id == id) || D.recentes.find(x => x.id == id);
  const debut = i ? new Date(i.date_debut.replace(' ', 'T')) : new Date();
  const min = Math.max(0, Math.round((Date.now() - debut) / 60000));
  dialogue(`<h3>Clôturer : ${esc(nomEq(i.equipement))}</h3><p class="tag">${esc(i.symptome || '')}</p><div class="champs">
    <label class="large">Cause trouvée<textarea name="cause" required></textarea></label>
    <label class="large">Travaux réalisés<textarea name="action" required></textarea></label>
    <label class="large">Pièces utilisées<input name="pieces"></label>
    <label>Minutes d'arrêt de production<input type="number" name="arret_min" min="0" value="${min}"></label>
    <label>Technicien<input name="technicien" value="${esc(i.technicien || D.utilisateur)}"></label></div>`,
    v => envoyer('intervention', {...v, id, equipement: i.equipement, type: i.type, statut: 'cloturee', date_debut: i.date_debut, symptome: i.symptome, code_alarme: i.code_alarme || ''}).then(() => message('Intervention clôturée')));
}
function fEquipement(id, parent){
  const e = id ? D.parId.get(String(id)) : {type: 'moteur', criticite: 'B', statut: 'marche', parent};
  const types = ['site', 'atelier', 'zone', 'machine', 'moteur', 'organe', 'armoire'];
  dialogue(`<h3>${id ? 'Modifier' : 'Nouvel équipement'}</h3><div class="champs">
    <label>Tag (unique)<input name="tag" required value="${esc(e.tag || (parent ? (D.parId.get(String(parent)) || {}).tag + '-' : 'DPR-'))}"></label>
    <label>Type<select name="type">${types.map(t => `<option ${e.type === t ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
    <label class="large">Nom<input name="nom" required value="${esc(e.nom || '')}"></label>
    <label class="large">Rattaché à<select name="parent">${optionsEq(e.parent)}</select></label>
    <label>Armoire<input name="armoire" value="${esc(e.armoire || '')}"></label>
    <label>Repères schéma (QM, KM, variateur)<input name="repere" value="${esc(e.repere || '')}"></label>
    <label>Folio du schéma<input name="folio" value="${esc(e.folio || '')}"></label>
    <label>Puissance (kW)<input name="puissance_kw" inputmode="decimal" value="${esc(e.puissance_kw || '')}"></label>
    <label>Vitesse / réglage<input name="vitesse" value="${esc(e.vitesse || '')}"></label>
    <label>Criticité<select name="criticite">${['A', 'B', 'C'].map(c => `<option ${e.criticite === c ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
    <label>Fabricant<input name="fabricant" value="${esc(e.fabricant || '')}"></label>
    <label>Modèle<input name="modele" value="${esc(e.modele || '')}"></label>
    <label>N° de série<input name="numserie" value="${esc(e.numserie || '')}"></label>
    <label>Statut<select name="statut">${['marche', 'panne', 'arret', 'rebut'].map(c => `<option ${e.statut === c ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
    <label class="large">Notes<textarea name="notes">${esc(e.notes || '')}</textarea></label></div>`,
    v => envoyer('equipement', {...v, id: id || ''}).then(r => { selection = String(r.id); message('Enregistré'); }));
}
function fPlan(id, eq){
  const p = id ? D.plans.find(x => x.id == id) : {frequence_j: 7, equipement: eq, actif: 1};
  dialogue(`<h3>${id ? 'Modifier le plan' : 'Nouveau plan préventif'}</h3><div class="champs">
    <label class="large">Nom<input name="nom" required value="${esc(p.nom || '')}"></label>
    <label class="large">Machine<select name="equipement" required>${optionsEq(p.equipement)}</select></label>
    <label>Tous les (jours)<input type="number" min="1" name="frequence_j" value="${p.frequence_j}"></label>
    <label>Durée (min)<input type="number" min="0" name="duree_min" value="${esc(p.duree_min || '')}"></label>
    <label>Dernière réalisation<input type="date" name="derniere" value="${esc(p.derniere || '')}"></label>
    <label>Technicien<input name="technicien" value="${esc(p.technicien || '')}"></label>
    <label class="large">Checklist (une étape par ligne)<textarea name="checklist" rows="6">${esc(p.checklist || '')}</textarea></label>
    <label class="large">Sécurité<textarea name="securite">${esc(p.securite || '')}</textarea></label>
    <label>Actif<select name="actif"><option value="1">Oui</option><option value="0" ${+p.actif ? '' : 'selected'}>Non</option></select></label></div>`,
    v => envoyer('plan', {...v, id: id || ''}).then(() => message('Plan enregistré')));
}
function fPlanVoir(id){
  const p = D.plans.find(x => x.id == id); const [c, t] = etatPlan(p);
  const etapes = String(p.checklist || '').split('\n').filter(Boolean);
  dialogue(`<h3>${esc(p.nom)}</h3><p>${esc(nomEq(p.equipement))} · tous les ${p.frequence_j} j · <span class="badge ${c}">${t}</span></p>
    ${p.securite ? `<p style="background:#fff1d6;color:#5a3a00;padding:8px;border-radius:8px">⚠ ${esc(p.securite)}</p>` : ''}
    <ol class="check">${etapes.map((s, i) => `<li><label style="display:flex;gap:8px;align-items:flex-start;color:inherit;font-size:.95rem"><input type="checkbox" name="e${i}" style="width:auto"> ${esc(s.replace(/^\d+\.\s*/, ''))}</label></li>`).join('')}</ol>
    <div class="champs"><label class="large">Relevés et remarques<textarea name="note" placeholder="Valeurs mesurées, anomalies…"></textarea></label><label>Technicien<input name="technicien" value="${esc(D.utilisateur)}"></label></div>
    ${D.droits.gerer ? `<p><a href="#" onclick="document.getElementById('dlg').close();fPlan(${p.id});return false">Modifier le plan</a></p>` : ''}`,
    v => { const faites = etapes.filter((s, i) => v['e' + i]).length;
      const note = `Checklist : ${faites}/${etapes.length} étape(s) cochée(s)` + (v.note ? '\n' + v.note : '');
      return envoyer('plan_fait', {id, note, technicien: v.technicien}).then(() => message('Préventif enregistré')); });
}
function fPiece(id){
  const p = id ? D.pieces.find(x => x.id == id) : {unite: 'pièce', stock: 0, seuil: 0};
  dialogue(`<h3>${id ? 'Modifier la pièce' : 'Nouvelle pièce'}</h3><div class="champs">
    <label class="large">Nom<input name="nom" required value="${esc(p.nom || '')}"></label>
    <label>Référence<input name="reference" value="${esc(p.reference || '')}"></label>
    <label>Unité<input name="unite" value="${esc(p.unite || '')}"></label>
    <label class="large">Machine<select name="equipement">${optionsEq(p.equipement)}</select></label>
    <label>Stock<input name="stock" inputmode="decimal" value="${+p.stock}"></label>
    <label>Seuil d'alerte<input name="seuil" inputmode="decimal" value="${+p.seuil}"></label>
    <label>Casier magasin<input name="emplacement" value="${esc(p.emplacement || '')}"></label>
    <label>Coût unitaire (DA)<input name="cout" inputmode="decimal" value="${esc(p.cout || '')}"></label>
    <label class="large">Fournisseur<input name="fournisseur" value="${esc(p.fournisseur || '')}"></label></div>`,
    v => envoyer('piece', {...v, id: id || ''}).then(() => message('Pièce enregistrée')));
}
function fAlarme(id){
  const a = id ? D.alarmes.find(x => x.id == id) : {};
  dialogue(`<h3>${id ? 'Modifier l\'alarme' : 'Nouvelle alarme'}</h3><div class="champs">
    <label>Code<input name="code" required value="${esc(a.code || '')}"></label>
    <label class="large">Texte affiché<input name="texte" required value="${esc(a.texte || '')}"></label>
    <label class="large">Machine concernée<select name="equipement">${optionsEq(a.equipement)}</select></label>
    <label class="large">Causes probables<textarea name="causes">${esc(a.causes || '')}</textarea></label>
    <label class="large">Que faire<textarea name="remede">${esc(a.remede || '')}</textarea></label></div>`,
    v => envoyer('alarme', {...v, id: id || ''}).then(() => message('Alarme enregistrée')));
}

document.querySelectorAll('#onglets button').forEach(b => b.addEventListener('click', () => { vue = b.dataset.vue; afficher(); }));
setInterval(() => { const a = document.activeElement; if (!$('#dlg').open && !(a && /INPUT|TEXTAREA|SELECT/.test(a.tagName))) charger(); }, 60000);  // rafraîchissement : les pannes déclarées ailleurs apparaissent
charger();
</script>
</body>
</html>
