<?php
/*
 * Prime de rendement DPR AXXAM — interface (une page, utilisable sur téléphone).
 * Accès : menu « Primes » de l'ERP, ou directement http://serveur:8080/custom/prime/index.php
 * Chef d'équipe : saisit la fiche du poste. Contrôleur : contrôle et valide.
 * Comptabilité : état mensuel par employé (impression, Excel). Direction : personnel, règle, clôture.
 */
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
$res = 0;
foreach (array('../../main.inc.php', '../../../main.inc.php', '../../../../main.inc.php') as $f) {
	if (!$res && file_exists(__DIR__.'/'.$f)) $res = @include __DIR__.'/'.$f;
}
if (!$res) die('main.inc.php introuvable');
if (!$user->admin && !$user->hasRight('prime', 'lire') && !$user->hasRight('prime', 'etat')) accessforbidden();
$jeton = newToken();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prime de rendement DPR AXXAM</title>
<style>
:root{--brique:#b4472b;--encre:#231a16;--gris:#6b5a51;--ligne:#e3d6ca;--fond:#f6f1ec;--carte:#fff;--ok:#2e7d4f;--alerte:#c77700;--non:#c62828;--bleu:#2f6fbf}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--encre:#f1e7de;--gris:#bba99c;--ligne:#3b2d27;--fond:#17110e;--carte:#231a16}}
*{box-sizing:border-box}
body{margin:0;font-family:"DejaVu Sans",Arial,sans-serif;font-size:15px;background:var(--fond);color:var(--encre)}
header{position:sticky;top:0;z-index:20;background:#2a1d17;color:#fff;display:flex;align-items:center;gap:12px;padding:8px 14px;flex-wrap:wrap}
header img{height:34px;background:#fff;border-radius:6px;padding:2px 6px}
header h1{font-size:1.05rem;margin:0;flex:1;min-width:150px}
header a{color:#f3c9b8;font-size:.85rem;text-decoration:none}
nav{display:flex;gap:4px;overflow-x:auto;background:#3a2a22;padding:0 10px;position:sticky;top:50px;z-index:19}
nav button{flex:0 0 auto;background:none;border:0;color:#e9d9cd;padding:11px 12px;font:inherit;cursor:pointer;border-bottom:3px solid transparent}
nav button[aria-current="true"]{color:#fff;border-bottom-color:var(--brique)}
nav button .nb{background:var(--alerte);color:#fff;border-radius:999px;padding:0 6px;font-size:.75rem;margin-left:4px}
main{max-width:1150px;margin:0 auto;padding:14px}
.barre{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px}
.btn{font:inherit;border:1px solid var(--brique);background:var(--carte);color:var(--brique);border-radius:9px;padding:9px 14px;cursor:pointer}
.btn.plein{background:var(--brique);color:#fff}
.btn.vert{background:var(--ok);border-color:var(--ok);color:#fff}
.btn.rouge{background:var(--non);border-color:var(--non);color:#fff}
.btn.grand{font-size:1.05rem;padding:13px 20px;font-weight:700}
.btn.petit{padding:5px 10px;font-size:.85rem}
.btn:disabled{opacity:.45;cursor:not-allowed}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.kpi{background:var(--carte);border:1px solid var(--ligne);border-radius:12px;padding:12px}
.kpi b{display:block;font-size:1.6rem}
.kpi span{color:var(--gris);font-size:.85rem}
.carte{background:var(--carte);border:1px solid var(--ligne);border-radius:12px;padding:14px;margin-bottom:14px;min-width:0}
.carte h2{font-size:1.05rem;margin:0 0 10px}
.regle{background:#fff6e5;border-color:#f0d29a;color:#4a3300}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]) .regle{background:#3a2a10;color:#f6deb0;border-color:#6a4d1a}}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:7px 6px;border-bottom:1px solid var(--ligne);vertical-align:middle}
th{color:var(--gris);font-weight:600;font-size:.8rem}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tr.clic{cursor:pointer}tr.clic:hover{background:rgba(180,71,43,.06)}
tfoot td{font-weight:700;border-top:2px solid var(--encre)}
.defile{overflow-x:auto}
.badge{display:inline-block;font-size:.75rem;padding:2px 8px;border-radius:999px;white-space:nowrap}
.badge.saisi{background:#fff1d6;color:#7a4b00}.badge.valide{background:#dcefe3;color:#1d5a37}.badge.refuse{background:#fde1dc;color:#8a1f12}
.vide{color:var(--gris);font-style:italic}
label{display:grid;gap:3px;font-size:.82rem;color:var(--gris)}
input,select,textarea{font:inherit;font-size:.95rem;padding:8px 9px;border:1px solid var(--ligne);border-radius:8px;background:var(--fond);color:var(--encre);min-width:0;width:100%}
input[type=checkbox]{width:22px;height:22px;accent-color:var(--brique)}
.champs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.champs .large{grid-column:1/-1}
.gros input{font-size:1.6rem;font-weight:700;text-align:center;padding:10px}
.ctrl{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--ligne);color:var(--encre);font-size:.95rem}
.membres{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px}
.membre{display:flex;align-items:center;gap:8px;padding:7px 9px;border:1px solid var(--ligne);border-radius:9px;color:var(--encre);font-size:.92rem}
.membre.coche{border-color:var(--brique);background:rgba(180,71,43,.06)}
.membre small{color:var(--gris)}
.membre .inj{margin-left:auto;display:flex;align-items:center;gap:4px;font-size:.75rem;color:var(--non)}
.membre .inj input{width:18px;height:18px;accent-color:var(--non)}
.calcul{font-size:1.05rem;line-height:1.7}
.calcul b{font-size:1.3rem}
.calcul .non{color:var(--non);font-weight:700}
.calcul .oui{color:var(--ok);font-weight:700}
.actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.message{position:fixed;bottom:16px;left:50%;transform:translateX(-50%);background:#2a1d17;color:#fff;padding:10px 16px;border-radius:10px;z-index:50;opacity:0;transition:opacity .3s;pointer-events:none}
.message.voir{opacity:1}
.alerte{border-left:5px solid var(--alerte)}
.imprime{display:none}
@media (max-width:640px){main{padding:10px}header img{height:28px}nav{top:46px}}
@media print{
  header,nav,.btn,.barre,.noprint{display:none!important}
  body{background:#fff;color:#000;font-size:11pt}
  .carte{border:0;padding:0}
  .imprime{display:block}
  th,td{border:1px solid #999;padding:4px 6px}
  @page{size:A4 portrait;margin:12mm}
}
</style>
</head>
<body>
<header>
  <img src="../timbredz/img/logo-dpr-axxam.png" alt="DPR AXXAM">
  <h1>Prime de rendement</h1>
  <span id="qui" style="font-size:.85rem;opacity:.85"></span>
  <a href="<?php echo DOL_URL_ROOT; ?>/index.php">← ERP</a>
</header>
<nav id="onglets"></nav>
<main id="vue"></main>
<div class="message" id="message" role="status"></div>

<script>
const API = 'api.php';
let R = null, jeton = <?php echo json_encode($jeton); ?>, vue = null, mois = null, F = null, fiche = null;
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const jour = d => d ? String(d).slice(0,10).split('-').reverse().join('/') : '';
const da = n => Math.round(+n || 0).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' DA';
const nomMois = m => { const [a, b] = m.split('-'); return ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][+b - 1] + ' ' + a; };
const moisDe = (m, d) => { let [a, b] = m.split('-').map(Number); b += d; while (b < 1) { b += 12; a--; } while (b > 12) { b -= 12; a++; } return a + '-' + String(b).padStart(2, '0'); };
const nomEq = id => (R.equipes.find(e => +e.id === +id) || {}).nom || '—';
const nomEmp = id => (R.employes.find(e => +e.id === +id) || {}).nom || '?';
const STATUT = {saisi: 'À contrôler', valide: 'Validée', refuse: 'Refusée'};
function message(t){ const m = $('#message'); m.textContent = t; m.classList.add('voir'); setTimeout(() => m.classList.remove('voir'), 2800); }

async function lire(op, params = {}){
  const r = await fetch(API + '?' + new URLSearchParams({op, ...params}), {credentials:'same-origin'});
  const j = await r.json().catch(() => ({erreur: 'Réponse invalide (' + r.status + ')'}));
  if (!r.ok || j.erreur) throw new Error(j.erreur || r.status);
  return j;
}
async function envoyer(op, donnees){
  const f = new FormData();
  f.append('op', op); f.append('token', jeton);
  for (const k in donnees) f.append(k, donnees[k] ?? '');
  const r = await fetch(API, {method:'POST', body:f, credentials:'same-origin'});
  let j = {}; try { j = await r.json(); } catch(e) { j = {erreur: 'Réponse invalide du serveur (' + r.status + ')'}; }
  if (!r.ok || j.erreur) { alert(j.erreur || ('Erreur ' + r.status)); throw new Error(j.erreur); }
  return j;
}

// ------------------------------------------------------------------ calcul (identique au serveur)
function calcul(f){
  const r = {objectif: +f.objectif, prix: +f.prix_wagon, seuil: +f.seuil_casse};
  const presents = (f.presences || []).filter(p => !+p.injustifie).length;
  const conditions = [
    ['Casse et déclassés ≤ ' + r.seuil + ' %', f.casse_pct !== '' && f.casse_pct !== null && +f.casse_pct <= r.seuil],
    ['Paquets bien présentés et cerclés', !!+f.ctrl_paquets],
    ['Consignes de sécurité respectées', !!+f.ctrl_securite],
    ['Poste propre en fin de travail', !!+f.ctrl_proprete],
  ];
  const audela = Math.max(0, (+f.wagons || 0) - r.objectif);
  const okQualite = conditions.every(c => c[1]);
  const prime = okQualite ? audela * r.prix : 0;
  return {r, presents, conditions, audela, okQualite, prime, part: presents ? Math.round(prime / presents) : 0};
}

// ------------------------------------------------------------------ navigation
function onglets(){
  const d = R.droits, t = [];
  if (d.saisir) t.push(['saisie', 'Fiche du poste']);
  if (d.lire) t.push(['fiches', 'Fiches du mois' + (F && F.fiches.some(f => f.statut === 'saisi') && d.valider ? ` <span class="nb">${F.fiches.filter(f => f.statut === 'saisi').length}</span>` : '')]);
  if (d.lire) t.push(['recap', 'Récap équipes']);
  if (d.etat || d.gerer) t.push(['etat', 'État pour la paie']);
  if (d.gerer) t.push(['personnel', 'Personnel']);
  if (d.gerer) t.push(['regle', 'Règle']);
  if (!vue || !t.some(x => x[0] === vue)) vue = t[0] ? t[0][0] : null;
  $('#onglets').innerHTML = t.map(([v, l]) => `<button data-vue="${v}" aria-current="${v === vue}">${l}</button>`).join('');
}
$('#onglets').onclick = e => { const b = e.target.closest('button'); if (b) { vue = b.dataset.vue; if (vue === 'saisie' && !fiche) fiche = null; afficher(); } };

async function charger(){
  try { R = await lire('tout'); } catch (e) { $('#vue').innerHTML = '<p class="carte">Erreur de chargement : ' + esc(e.message) + '. Reconnectez-vous à l\'ERP.</p>'; return; }
  jeton = R.jeton || jeton;
  mois = mois || R.aujourdhui.slice(0, 7);
  $('#qui').textContent = R.utilisateur;
  if (R.droits.lire) F = await lire('fiches', {mois}).catch(() => null);
  afficher();
}
async function rechargerFiches(){ if (R.droits.lire) F = await lire('fiches', {mois}); }
function choixMois(){
  return `<div class="barre"><button class="btn petit" data-mois="-1">◀</button><b style="font-size:1.1rem;align-self:center">${nomMois(mois)}</b><button class="btn petit" data-mois="1">▶</button></div>`;
}
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-mois]');
  if (!b) return;
  mois = moisDe(mois, +b.dataset.mois);
  await rechargerFiches(); afficher();
});

function afficher(){
  onglets();
  const v = $('#vue');
  v.onchange = v.oninput = null;
  if (!vue) { v.innerHTML = '<p class="carte">Aucun droit sur les primes : demandez à l\'administrateur.</p>'; return; }
  ({saisie: vSaisie, fiches: vFiches, recap: vRecap, etat: vEtat, personnel: vPersonnel, regle: vRegle})[vue](v);
}

// ------------------------------------------------------------------ fiche du poste (chef d'équipe, contrôleur)
function nouvelleFiche(){
  const eq = R.equipes.find(e => +e.actif);
  return {id: 0, date_poste: R.aujourdhui, equipe: eq ? eq.id : '', horaire: '', chef: R.utilisateur, brique: 'B8', wagons: '', casse_pct: '',
    ctrl_paquets: 0, ctrl_securite: 0, ctrl_proprete: 0, statut: 'saisi', objectif: R.regle.objectif, prix_wagon: R.regle.prix_wagon, seuil_casse: R.regle.seuil_casse,
    presences: eq ? R.employes.filter(m => +m.actif && +m.equipe === +eq.id).map(m => ({employe: +m.id, injustifie: 0})) : []};
}
function vSaisie(v){
  if (!R.equipes.length || !R.employes.length) {
    v.innerHTML = `<div class="carte alerte"><h2>Personnel pas encore saisi</h2><p>Avant la première fiche, la direction doit créer les équipes et le personnel (onglet « Personnel »).</p></div>`; return;
  }
  const f = fiche || (fiche = nouvelleFiche());
  const fige = f.statut !== 'saisi' && !R.droits.valider;
  const ctl = R.droits.valider;
  const dis = fige ? 'disabled' : '';
  const membres = R.employes.filter(m => +m.actif || f.presences.some(p => +p.employe === +m.id))
    .sort((a, b) => ((+b.equipe === +f.equipe) - (+a.equipe === +f.equipe)) || a.nom.localeCompare(b.nom));
  v.innerHTML = `
  <div class="barre"><button class="btn" id="nouv">+ Nouvelle fiche</button>${f.id ? `<span class="badge ${f.statut}" style="align-self:center">${STATUT[f.statut]}</span>` : ''}
    ${f.valide_par ? `<span class="vide" style="align-self:center">contrôlée par ${esc(f.valide_par)}</span>` : ''}</div>
  ${fige ? '<div class="carte alerte">Cette fiche a déjà été contrôlée : seul le contrôleur peut la modifier.</div>' : ''}
  ${f.statut === 'refuse' ? `<div class="carte alerte"><b>Refusée :</b> ${esc(f.motif)}</div>` : ''}
  <form id="ff" class="carte">
    <h2>Fiche journalière du poste</h2>
    <div class="champs">
      <label>Date<input type="date" name="date_poste" value="${esc(f.date_poste)}" max="${R.aujourdhui}" required ${dis}></label>
      <label>Équipe<select name="equipe" required ${dis}>${R.equipes.filter(e => +e.actif || +e.id === +f.equipe).map(e => `<option value="${e.id}" ${+e.id === +f.equipe ? 'selected' : ''}>${esc(e.nom)}</option>`).join('')}</select></label>
      <label>Poste (horaire)<select name="horaire" ${dis}>${['', '6h-14h', '14h-22h', '22h-6h', 'Journée'].map(h => `<option ${h === f.horaire ? 'selected' : ''}>${h}</option>`).join('')}</select></label>
      <label>Chef de poste<input name="chef" value="${esc(f.chef)}" ${dis}></label>
      <label>Brique<select name="brique" ${dis}>${['B8', 'B12', 'Autre'].map(h => `<option ${h === f.brique ? 'selected' : ''}>${h}</option>`).join('')}</select></label>
    </div>
  </form>
  <div class="carte">
    <h2>Production du poste</h2>
    <div class="champs">
      <label class="gros" style="grid-column:span 2">Wagons dépilés (base de la prime)<input form="ff" type="number" name="wagons" min="0" max="200" inputmode="numeric" value="${esc(f.wagons)}" required ${dis}></label>
      <label>Mouleuse : chariots<input form="ff" type="number" name="chariots" min="0" value="${esc(f.chariots ?? '')}" ${dis}></label>
      <label>Empilage : wagons chargés<input form="ff" type="number" name="wagons_charges" min="0" value="${esc(f.wagons_charges ?? '')}" ${dis}></label>
      <label>Four : wagons enfournés<input form="ff" type="number" name="enfournes" min="0" value="${esc(f.enfournes ?? '')}" ${dis}></label>
      <label>Four : wagons défournés<input form="ff" type="number" name="defournes" min="0" value="${esc(f.defournes ?? '')}" ${dis}></label>
      <label>Emballage : paquets<input form="ff" type="number" name="paquets" min="0" value="${esc(f.paquets ?? '')}" ${dis}></label>
    </div>
  </div>
  <div class="carte">
    <h2>Membres présents <small class="vide">— cocher les présents ; « retard » = absence ou retard injustifié, pas de part</small></h2>
    <div class="membres">${membres.map(m => { const p = f.presences.find(x => +x.employe === +m.id); return `
      <label class="membre ${p ? 'coche' : ''}"><input type="checkbox" data-emp="${m.id}" ${p ? 'checked' : ''} ${dis}>
        <span>${esc(m.nom)}<br><small>${esc(m.poste || '')}${+m.equipe !== +f.equipe ? ' · ' + esc(nomEq(m.equipe)) : ''}</small></span>
        ${p ? `<span class="inj"><input type="checkbox" data-inj="${m.id}" ${+p.injustifie ? 'checked' : ''} ${dis}>retard</span>` : ''}</label>`; }).join('')}</div>
  </div>
  <div class="carte">
    <h2>Qualité ${ctl ? '' : '<small class="vide">— à remplir par le chef de poste, vérifié par le contrôleur</small>'}</h2>
    <div class="champs"><label>Casse et déclassés (%)<input form="ff" type="number" name="casse_pct" min="0" max="100" step="0.1" inputmode="decimal" value="${esc(f.casse_pct ?? '')}" ${dis}></label></div>
    <label class="ctrl"><input form="ff" type="checkbox" name="ctrl_paquets" ${+f.ctrl_paquets ? 'checked' : ''} ${dis}> Paquets bien présentés, alignés et cerclés</label>
    <label class="ctrl"><input form="ff" type="checkbox" name="ctrl_securite" ${+f.ctrl_securite ? 'checked' : ''} ${dis}> Consignes de sécurité respectées</label>
    <label class="ctrl"><input form="ff" type="checkbox" name="ctrl_proprete" ${+f.ctrl_proprete ? 'checked' : ''} ${dis}> Poste propre en fin de travail</label>
  </div>
  <div class="carte regle" id="calc"></div>
  <div class="actions noprint">
    ${f.id && !fige ? '<button class="btn" id="suppr">Supprimer</button>' : ''}
    ${!fige ? '<button class="btn plein grand" id="enreg">Enregistrer la fiche</button>' : ''}
    ${ctl && f.id && f.statut !== 'saisi' ? '<button class="btn" id="rouvrir">Remettre « à contrôler »</button>' : ''}
    ${ctl && f.id && f.statut !== 'refuse' ? '<button class="btn rouge grand" id="refuser">Refuser</button>' : ''}
    ${ctl && f.id && f.statut !== 'valide' ? '<button class="btn vert grand" id="valider">Valider la fiche</button>' : ''}
  </div>
  ${ctl && !f.id ? '<p class="vide">Enregistrez la fiche, puis validez-la.</p>' : ''}`;
  const lireForm = () => {
    const d = Object.fromEntries(new FormData($('#ff')));
    ['ctrl_paquets', 'ctrl_securite', 'ctrl_proprete'].forEach(k => d[k] = $(`[name=${k}]`).checked ? 1 : 0);
    d.presences = [...v.querySelectorAll('[data-emp]:checked')].map(c => ({employe: +c.dataset.emp, injustifie: v.querySelector(`[data-inj="${c.dataset.emp}"]`)?.checked ? 1 : 0}));
    return d;
  };
  const maj = () => {
    if (!fige) Object.assign(f, lireForm());
    const c = calcul(f);
    $('#calc').innerHTML = `<h2>Calcul de la prime ${f.statut !== 'valide' ? '<small>(prévision — comptée une fois la fiche validée par le contrôleur)</small>' : ''}</h2><div class="calcul">
      Wagons dépilés <b>${+f.wagons || 0}</b> − objectif ${c.r.objectif} = <b>${c.audela}</b> wagon(s) au-delà<br>
      ${c.conditions.map(([t, ok]) => `<span class="${ok ? 'oui' : 'non'}">${ok ? '✔' : '✘'}</span> ${esc(t)}`).join(' &nbsp; ')}<br>
      Prime de l'équipe : ${c.audela} × ${da(c.r.prix)} = <b>${da(c.prime)}</b>${!c.okQualite && c.audela ? ' <span class="non">(conditions non remplies : pas de prime)</span>' : ''}<br>
      Présents ayant droit : <b>${c.presents}</b> → part par personne : <b>${da(c.part)}</b></div>`;
  };
  v.onchange = e => {
    if (e.target.dataset.emp !== undefined || e.target.name === 'equipe') {
      Object.assign(f, lireForm());
      if (e.target.name === 'equipe' && !f.id) f.presences = R.employes.filter(m => +m.actif && +m.equipe === +f.equipe).map(m => ({employe: +m.id, injustifie: 0}));
      vSaisie(v); return;
    }
    maj();
  };
  v.oninput = maj;
  maj();
  $('#nouv').onclick = () => { fiche = nouvelleFiche(); vSaisie(v); };
  const enregistrer = async () => {
    const d = lireForm();
    if (!$('#ff').reportValidity()) return null;
    if (d.wagons === '') { alert('Saisissez le nombre de wagons dépilés.'); return null; }
    if (!d.presences.length) { alert('Cochez les membres présents.'); return null; }
    const r = await envoyer('fiche', {...d, id: f.id, presents: JSON.stringify(d.presences)});
    f.date_poste = d.date_poste;
    return r.id;
  };
  const rouvrirFiche = async id => { mois = String(f.date_poste).slice(0, 7); await rechargerFiches(); fiche = F.fiches.find(x => +x.id === +id) || null; afficher(); };
  if ($('#enreg')) $('#enreg').onclick = async () => { try { const id = await enregistrer(); if (id) { message('Fiche enregistrée'); await rouvrirFiche(id); } } catch (e) {} };
  if ($('#valider')) $('#valider').onclick = async () => { try { const id = fige ? f.id : await enregistrer(); if (!id) return; await envoyer('controle', {id, decision: 'valide'}); message('Fiche validée'); await rouvrirFiche(id); } catch (e) {} };
  if ($('#refuser')) $('#refuser').onclick = async () => { const m = prompt('Motif du refus (sera visible par le chef d\'équipe) :'); if (!m) return; try { await envoyer('controle', {id: f.id, decision: 'refuse', motif: m}); message('Fiche refusée'); await rouvrirFiche(f.id); } catch (e) {} };
  if ($('#rouvrir')) $('#rouvrir').onclick = async () => { try { await envoyer('controle', {id: f.id, decision: 'rouvrir'}); await rouvrirFiche(f.id); } catch (e) {} };
  if ($('#suppr')) $('#suppr').onclick = async () => { if (!confirm('Supprimer cette fiche ?')) return; try { await envoyer('supprimer_fiche', {id: f.id}); fiche = null; await rechargerFiches(); message('Fiche supprimée'); afficher(); } catch (e) {} };
}

// ------------------------------------------------------------------ fiches du mois
function vFiches(v){
  const l = F ? F.fiches : [];
  const attente = l.filter(f => f.statut === 'saisi');
  v.innerHTML = choixMois() + (F && F.cloture ? `<div class="carte">Mois clôturé le ${jour(F.cloture.date)} par ${esc(F.cloture.par)} : fiches en lecture seule.</div>` : '') + `
  <div class="kpis">
    <div class="kpi"><b>${l.length}</b><span>fiches</span></div>
    <div class="kpi"><b style="color:var(--alerte)">${attente.length}</b><span>à contrôler</span></div>
    <div class="kpi"><b>${l.filter(f => f.statut === 'valide' && +f.prime > 0).length}</b><span>postes primés</span></div>
    <div class="kpi"><b>${da(l.reduce((s, f) => s + (+f.prime || 0), 0))}</b><span>primes validées</span></div>
  </div>
  <div class="carte defile"><table><thead><tr><th>Date</th><th>Équipe</th><th>Poste</th><th class="n">Wagons</th><th class="n">Casse</th><th>État</th><th class="n">Présents</th><th class="n">Prime équipe</th><th class="n">Part</th><th>Saisie</th></tr></thead><tbody>
  ${l.map(f => `<tr class="clic" data-id="${f.id}"><td>${jour(f.date_poste)}</td><td>${esc(nomEq(f.equipe))}</td><td>${esc(f.horaire)}</td><td class="n"><b>${f.wagons}</b></td>
    <td class="n">${f.casse_pct === null ? '—' : (+f.casse_pct).toLocaleString('fr-FR') + ' %'}</td><td><span class="badge ${f.statut}">${STATUT[f.statut]}</span></td>
    <td class="n">${f.statut === 'saisi' ? f.presences.filter(p => !p.injustifie).length : f.presents}</td><td class="n">${f.statut === 'saisi' ? '<span class="vide">' + da(calcul(f).prime) + ' ?</span>' : da(f.prime)}</td>
    <td class="n">${f.statut === 'saisi' ? '' : da(f.part)}</td><td class="vide">${esc(f.saisi_par)}</td></tr>`).join('') || '<tr><td colspan="10" class="vide">Aucune fiche ce mois-ci.</td></tr>'}
  </tbody></table></div>`;
  v.querySelectorAll('tr[data-id]').forEach(tr => tr.onclick = () => { fiche = l.find(f => +f.id === +tr.dataset.id); vue = R.droits.saisir || R.droits.valider ? 'saisie' : 'fiches'; if (vue === 'saisie') afficher(); });
}

// ------------------------------------------------------------------ récap par équipe (jour par jour)
function vRecap(v){
  const l = (F ? F.fiches : []).slice().reverse();
  const [a, m] = mois.split('-').map(Number), nj = new Date(a, m, 0).getDate();
  const eqs = R.equipes.filter(e => +e.actif || l.some(f => +f.equipe === +e.id));
  v.innerHTML = choixMois() + eqs.map(e => {
    const fe = l.filter(f => +f.equipe === +e.id);
    const tot = fe.reduce((s, f) => s + (+f.prime || 0), 0);
    return `<div class="carte defile"><h2>${esc(e.nom)} — ${fe.length} poste(s), ${fe.filter(f => +f.prime > 0).length} primé(s), ${da(tot)}</h2>
    <table><thead><tr><th>Jour</th><th class="n">Wagons dépilés</th><th class="n">Au-delà de ${R.regle.objectif}</th><th class="n">Casse</th><th>Qualité</th><th class="n">Prime équipe</th><th class="n">Présents</th><th class="n">Part / pers.</th><th>État</th></tr></thead><tbody>
    ${Array.from({length: nj}, (_, i) => { const d = `${mois}-${String(i + 1).padStart(2, '0')}`; const x = fe.filter(f => f.date_poste === d); if (!x.length) return ''; return x.map(f => { const c = calcul(f); return `
      <tr><td>${jour(d)} ${esc(f.horaire)}</td><td class="n">${f.wagons}</td><td class="n">${c.audela}</td><td class="n">${f.casse_pct === null ? '—' : (+f.casse_pct).toLocaleString('fr-FR') + ' %'}</td>
      <td>${c.okQualite ? 'O' : 'N'}</td><td class="n">${da(f.prime)}</td><td class="n">${f.presents}</td><td class="n">${da(f.part)}</td><td><span class="badge ${f.statut}">${STATUT[f.statut]}</span></td></tr>`; }).join(''); }).join('') || '<tr><td colspan="9" class="vide">Aucun poste.</td></tr>'}
    </tbody><tfoot><tr><td>Total</td><td class="n">${fe.reduce((s, f) => s + +f.wagons, 0)}</td><td class="n">${fe.reduce((s, f) => s + calcul(f).audela, 0)}</td><td></td><td></td><td class="n">${da(tot)}</td><td></td><td></td><td></td></tr></tfoot></table></div>`;
  }).join('');
}

// ------------------------------------------------------------------ état mensuel pour la paie (comptabilité)
async function vEtat(v){
  v.innerHTML = choixMois() + '<p class="vide">Calcul…</p>';
  let E;
  try { E = await lire('etat', {mois}); } catch (e) { v.innerHTML = choixMois() + `<p class="carte">${esc(e.message)}</p>`; return; }
  if (vue !== 'etat') return;
  const tot = E.employes.reduce((s, e) => s + +e.total, 0);
  const fini = mois < R.aujourdhui.slice(0, 7);
  v.innerHTML = choixMois() + `
  <div class="carte ${E.cloture ? '' : 'alerte'} noprint">${E.cloture
    ? `<b>Mois clôturé</b> le ${jour(E.cloture.date)} à ${String(E.cloture.date).slice(11, 16)} par ${esc(E.cloture.par)} — état définitif, transmis à la comptabilité.`
    : `<b>État provisoire</b> : il se met à jour à chaque fiche validée. ${E.en_attente ? `<br><b style="color:var(--alerte)">${E.en_attente} fiche(s) attendent le contrôleur.</b>` : ''}
       ${fini ? '' : '<br>Le mois n\'est pas terminé : la clôture sera possible à partir du 1er du mois suivant.'}`}</div>
  <div class="barre noprint">
    <button class="btn plein" onclick="window.print()">Imprimer l'état</button>
    <a class="btn" href="${API}?op=csv&mois=${mois}">Télécharger pour Excel</a>
    ${R.droits.gerer && !E.cloture ? `<button class="btn vert" id="clot" ${!fini || E.en_attente ? 'disabled' : ''}>Clôturer et transmettre à la comptabilité</button>` : ''}
    ${R.droits.gerer && E.cloture ? '<button class="btn" id="reouv">Rouvrir le mois</button>' : ''}
  </div>
  <div class="carte defile">
    <div class="imprime"><b>SARL DPR AXXAM</b> — ZAC Hellouane, Ouzellaguen, Béjaïa<br><br></div>
    <h2>État mensuel des primes de rendement — ${nomMois(mois)} ${E.cloture ? '' : '<span class="badge saisi">provisoire</span>'}</h2>
    <table><thead><tr><th>N°</th><th>Matricule</th><th>Nom et prénom</th><th>Poste</th><th>Équipe</th><th class="n">Jours de présence</th><th class="n">Jours primés</th><th class="n">Total prime</th><th>Émargement</th></tr></thead><tbody>
    ${E.employes.map((e, i) => `<tr><td>${i + 1}</td><td>${esc(e.matricule)}</td><td>${esc(e.nom)}</td><td>${esc(e.poste)}</td><td>${esc(e.equipe)}</td>
      <td class="n">${e.jours}</td><td class="n">${e.jours_primes}</td><td class="n"><b>${da(e.total)}</b></td><td style="min-width:90px"></td></tr>`).join('') || '<tr><td colspan="9" class="vide">Aucun employé.</td></tr>'}
    </tbody><tfoot><tr><td colspan="7">Total général</td><td class="n">${da(tot)}</td><td></td></tr></tfoot></table>
    <div class="imprime"><br><table style="border:0"><tr><td style="border:0;height:70px;vertical-align:top">Chef de production</td><td style="border:0;vertical-align:top">Comptabilité / Paie</td><td style="border:0;vertical-align:top">Le Gérant</td></tr></table></div>
  </div>`;
  if ($('#clot')) $('#clot').onclick = async () => { if (!confirm(`Clôturer ${nomMois(mois)} (${da(tot)}) ? Plus aucune fiche du mois ne pourra être modifiée.`)) return; try { await envoyer('cloturer', {mois}); message('Mois clôturé'); await rechargerFiches(); vEtat(v); } catch (e) {} };
  if ($('#reouv')) $('#reouv').onclick = async () => { if (!confirm('Rouvrir le mois ? La comptabilité devra reprendre l\'état ensuite.')) return; try { await envoyer('rouvrir_mois', {mois}); await rechargerFiches(); vEtat(v); } catch (e) {} };
}

// ------------------------------------------------------------------ personnel et équipes (direction)
function vPersonnel(v){
  const opts = sel => '<option value="">—</option>' + R.equipes.map(e => `<option value="${e.id}" ${+e.id === +sel ? 'selected' : ''}>${esc(e.nom)}</option>`).join('');
  v.innerHTML = `
  <div class="carte"><h2>Équipes</h2>
    <div class="barre">${R.equipes.map(e => `<span class="badge ${+e.actif ? 'valide' : ''}" style="font-size:.9rem">${esc(e.nom)}</span>`).join(' ') || '<span class="vide">aucune</span>'}</div>
    <form id="fe" class="barre"><label>Nouvelle équipe<input name="nom" required placeholder="Équipe A"></label><button class="btn plein">Ajouter</button></form></div>
  <div class="carte"><h2>Ajouter du personnel en une fois</h2>
    <form id="fl" class="champs"><label class="large">Un nom par ligne (« Nom ; poste ; matricule ; équipe »), ou coller directement les lignes copiées de la liste des salariés (Excel de la paie) — un matricule déjà connu est mis à jour, pas dupliqué<textarea name="liste" rows="5" placeholder="BENALI Karim ; dépileur ; 0123"></textarea></label>
    <label>Équipe<select name="equipe">${opts('')}</select></label><div style="align-self:end"><button class="btn plein">Ajouter la liste</button></div></form></div>
  <div class="carte defile"><h2>Personnel (${R.employes.filter(e => +e.actif).length} actifs)</h2>
    <table><thead><tr><th>Matricule</th><th>Nom et prénom</th><th>Poste</th><th>Équipe</th><th>Actif</th><th></th></tr></thead><tbody>
    ${R.employes.map(e => `<tr data-id="${e.id}"><td><input name="matricule" value="${esc(e.matricule)}" style="width:90px"></td><td><input name="nom" value="${esc(e.nom)}"></td>
      <td><input name="poste" value="${esc(e.poste)}"></td><td><select name="equipe">${opts(e.equipe)}</select></td>
      <td><input type="checkbox" name="actif" ${+e.actif ? 'checked' : ''}></td><td><button class="btn petit" data-enr>Enregistrer</button></td></tr>`).join('') || '<tr><td colspan="6" class="vide">Aucun employé.</td></tr>'}
    </tbody></table></div>`;
  $('#fe').onsubmit = async e => { e.preventDefault(); try { await envoyer('equipe', Object.fromEntries(new FormData(e.target))); await charger(); message('Équipe ajoutée'); } catch (x) {} };
  $('#fl').onsubmit = async e => { e.preventDefault(); try { const r = await envoyer('employes_lot', Object.fromEntries(new FormData(e.target))); await charger(); message(r.crees + ' ajoutée(s), ' + r.maj + ' mise(s) à jour'); } catch (x) {} };
  v.querySelectorAll('[data-enr]').forEach(b => b.onclick = async () => {
    const tr = b.closest('tr'), g = n => tr.querySelector(`[name=${n}]`);
    try { await envoyer('employe', {id: tr.dataset.id, matricule: g('matricule').value, nom: g('nom').value, poste: g('poste').value, equipe: g('equipe').value, actif: g('actif').checked ? 1 : 0}); await charger(); message('Enregistré'); } catch (x) {}
  });
}

// ------------------------------------------------------------------ règle de la prime (direction)
function vRegle(v){
  const r = R.regle;
  v.innerHTML = `<form id="fr" class="carte"><h2>Règle de la prime</h2>
    <div class="champs"><label>Objectif : wagons dépilés par équipe et par poste<input name="objectif" type="number" min="0" value="${r.objectif}" required></label>
    <label>Prime par wagon au-delà (DA)<input name="prix_wagon" type="number" min="0" step="50" value="${r.prix_wagon}" required></label>
    <label>Seuil de casse et déclassés (%)<input name="seuil_casse" type="number" min="0" max="100" step="0.5" value="${r.seuil_casse}" required></label></div>
    <p class="vide">Une modification s'applique aux fiches saisies ou corrigées ensuite ; les fiches déjà validées gardent la règle du jour.</p>
    <div class="actions"><button class="btn plein">Enregistrer</button></div></form>
    <div class="carte regle"><h2>Rappel</h2>Prime de l'équipe = (wagons dépilés − ${r.objectif}) × ${da(r.prix_wagon)}, si la fiche est validée par le contrôleur,
    la casse ≤ ${r.seuil_casse} %, les paquets bien présentés, la sécurité et la propreté respectées. Elle est partagée à parts égales entre les présents,
    sauf absence ou retard injustifié. Les primes du mois sont payées avec le salaire du mois.</div>`;
  $('#fr').onsubmit = async e => { e.preventDefault(); try { await envoyer('regle', Object.fromEntries(new FormData(e.target))); await charger(); message('Règle enregistrée'); } catch (x) {} };
}

charger();
</script>
</body>
</html>
