#!/usr/bin/env python3
"""Écrit un classeur .xls au format exact des fichiers d'import de PC Compta (DLG).

Les enregistrements de mise en forme (polices, styles, largeurs de colonnes, fenêtres)
sont repris tels quels de modele_pccompta.json, extrait d'un fichier PC Compta ; seules les
données changent : table des textes (SST), cellules (LABELSST, RK, NUMBER), dimensions,
positions des feuilles. Le conteneur OLE est construit comme dans les fichiers PC Compta
(flux Workbook en tête, répertoire, puis table d'allocation).

Usage :
    python3 pccompta_xls.py export.json [sortie.xls]     JSON → .xls
    python3 pccompta_xls.py --verifier fichier.xls        relit un .xls PC Compta, le
                                                          réécrit et compare octet par octet
Bibliothèque standard uniquement.
"""
import json
import math
import struct
import sys
from pathlib import Path

MODELE = Path(__file__).resolve().parent / "modele_pccompta.json"
XF = 0x0F
BLOC = 8224                      # taille maximale des données d'un enregistrement BIFF8
FIN, LIBRE, FATSECT = 0xFFFFFFFE, 0xFFFFFFFF, 0xFFFFFFFD


def rec(t, data=b""):
    return struct.pack("<HH", t, len(data)) + data


# ------------------------------------------------------------------ textes
def chaine(s):
    """Chaîne BIFF8 (cch 16 bits) : 8 bits si possible, sinon UTF-16."""
    try:
        return struct.pack("<HB", len(s), 0) + s.encode("latin-1"), 1
    except UnicodeEncodeError:
        return struct.pack("<HB", len(s), 1) + s.encode("utf-16-le"), 2


def table_textes(textes, total, debut_flux):
    """SST + CONTINUE découpés comme Excel ; renvoie (octets, positions pour EXTSST)."""
    blocs, bloc = [], bytearray(struct.pack("<II", total, len(textes)))
    positions = []                                   # (n° de bloc, décalage dans le bloc)
    for s in textes:
        donnees, taille = chaine(s)
        entete = donnees[:3]
        car = donnees[3:]
        place = BLOC - len(bloc)
        if len(donnees) <= place:
            positions.append((len(blocs), len(bloc)))
            bloc += donnees
            continue
        if place <= len(entete) + taille - 1:        # pas même un caractère : nouveau bloc
            blocs.append(bytes(bloc))
            bloc = bytearray()
            positions.append((len(blocs), 0))
            bloc += donnees
            continue
        positions.append((len(blocs), len(bloc)))
        n = (place - len(entete)) // taille * taille
        bloc += entete + car[:n]
        car = car[n:]
        while car:
            blocs.append(bytes(bloc))
            n = min(len(car), (BLOC - 1) // taille * taille)
            bloc = bytearray(bytes([taille - 1]) + car[:n])
            car = car[n:]
    blocs.append(bytes(bloc))

    octets, debuts = bytearray(), []
    for i, b in enumerate(blocs):
        debuts.append(debut_flux + len(octets))
        octets += rec(0x00FC if i == 0 else 0x003C, b)
    return bytes(octets), [(debuts[b] + 4 + d, d + 4) for b, d in positions]


def extsst(positions):
    n = len(positions)
    par_seau = max(8, n // 128 + 1)
    data = struct.pack("<H", par_seau)
    for i in range(0, n, par_seau):
        ib, cb = positions[i]
        data += struct.pack("<IHH", ib, cb, 0)
    return rec(0x00FF, data)


# ------------------------------------------------------------------ cellules
def nombre(ligne, col, v):
    if float(v).is_integer() and -(1 << 29) <= v < (1 << 29):
        return rec(0x027E, struct.pack("<HHHI", ligne, col, XF, (int(v) << 2 | 2) & 0xFFFFFFFF))
    c = round(v * 100)
    if abs(v * 100 - c) < 1e-6 and -(1 << 29) <= c < (1 << 29) and c / 100 == v:
        return rec(0x027E, struct.pack("<HHHI", ligne, col, XF, (c << 2 | 3) & 0xFFFFFFFF))
    return rec(0x0203, struct.pack("<HHHd", ligne, col, XF, float(v)))


def classeur(feuilles):
    """feuilles : liste de (nom, lignes) dans l'ordre du modèle ; lignes = listes de str / nombres."""
    modele = json.loads(MODELE.read_text(encoding="utf-8"))
    entete_g = b"".join(rec(t, bytes.fromhex(h)) for t, h in modele["globaux"])
    apres = b"".join(rec(t, bytes.fromhex(h)) for t, h in modele["apres_feuilles"])

    index, textes, total = {}, [], 0
    for _, lignes in feuilles:
        for ligne in lignes:
            for v in ligne:
                if isinstance(v, str):
                    total += 1
                    if v not in index:
                        index[v] = len(textes)
                        textes.append(v)

    def boundsheet(nom, position):
        return rec(0x0085, struct.pack("<IBBBB", position, 0, 0, len(nom), 1) + nom.encode("utf-16-le"))

    taille_bs = sum(len(boundsheet(n, 0)) for n, _ in feuilles)
    debut_sst = len(entete_g) + taille_bs + len(apres)
    sst, positions = table_textes(textes, total, debut_sst)
    globaux_fin = sst + extsst(positions) + rec(0x000A)

    corps, debuts = bytearray(), []
    base = debut_sst + len(globaux_fin)
    for nom, lignes in feuilles:
        m = modele["feuilles"][nom]
        debuts.append(base + len(corps))
        nb_col = max((len(l) for l in lignes), default=0)
        for t, h in m["head"]:
            data = bytes.fromhex(h)
            if t == 0x0200:
                data = struct.pack("<IIHHH", 0, len(lignes), 0, nb_col, 0)
            corps += rec(t, data)
        for i, ligne in enumerate(lignes):
            for j, v in enumerate(ligne):
                if isinstance(v, str):
                    corps += rec(0x00FD, struct.pack("<HHHI", i, j, XF, index[v]))
                else:
                    corps += nombre(i, j, v)
        for t, h in m["tail"]:
            corps += rec(t, bytes.fromhex(h))

    bs = b"".join(boundsheet(n, p) for (n, _), p in zip(feuilles, debuts))
    return entete_g + bs + apres + globaux_fin + bytes(corps)


# ------------------------------------------------------------------ conteneur OLE
def ole(flux):
    taille = len(flux)
    if taille < 4096:
        flux += b"\0" * (4096 - taille)
        taille = 4096
    n = math.ceil(len(flux) / 512)
    flux += b"\0" * (n * 512 - len(flux))
    f = 1
    while n + 1 + f > f * 128:
        f += 1
    rep = n
    fats = list(range(n + 1, n + 1 + f))
    fat = [i + 1 for i in range(n - 1)] + [FIN, FIN] + [FATSECT] * f
    fat += [LIBRE] * (f * 128 - len(fat))

    def entree(nom, type_, enfant, debut, taille_):
        nom16 = (nom.encode("utf-16-le") + b"\0\0") if nom else b""
        return (nom16.ljust(64, b"\0") + struct.pack("<HBB", len(nom16), type_, 1 if nom else 0)
                + struct.pack("<III", LIBRE, LIBRE, enfant) + b"\0" * 16 + b"\0" * 4 + b"\0" * 16
                + struct.pack("<II", debut, taille_) + b"\0" * 4)

    repertoire = (entree("Root Entry", 5, 1, FIN, 0) + entree("Workbook", 2, LIBRE, 0, taille)
                  + entree("", 0, LIBRE, 0, 0) + entree("", 0, LIBRE, 0, 0))
    entete = (bytes.fromhex("d0cf11e0a1b11ae1") + b"\0" * 16
              + struct.pack("<HHHHH", 0x3E, 3, 0xFFFE, 9, 6) + b"\0" * 6
              + struct.pack("<IIIIIIIII", 0, f, rep, 0, 4096, FIN, 0, FIN, 0)
              + struct.pack("<109I", *(fats + [LIBRE] * (109 - f))))
    return entete + flux + repertoire + struct.pack(f"<{len(fat)}I", *fat)


# ------------------------------------------------------------------ lecture (vérification)
def lire_xls(chemin):
    raw = Path(chemin).read_bytes()
    ss = 512
    nfat, rep = struct.unpack("<II", raw[44:52])
    difat = struct.unpack("<109I", raw[76:512])[:nfat]
    fat = []
    for s in difat:
        fat += struct.unpack("<128I", raw[512 + s * ss:512 + (s + 1) * ss])
    d = raw[512 + rep * ss:512 + (rep + 1) * ss]
    debut, taille = struct.unpack("<II", d[128 + 116:128 + 124])
    flux, s = bytearray(), debut
    while s < 0xFFFFFFF0:
        flux += raw[512 + s * ss:512 + (s + 1) * ss]
        s = fat[s]
    flux = bytes(flux[:taille])
    recs, pos = [], 0
    while pos + 4 <= len(flux):
        t, l = struct.unpack("<HH", flux[pos:pos + 4])
        recs.append((t, flux[pos + 4:pos + 4 + l]))
        pos += 4 + l
    # SST
    sst_data, i = b"", 0
    blocs = []
    for t, data in recs:
        if t == 0x00FC:
            blocs = [data[8:]]
        elif t == 0x003C and blocs is not None and blocs:
            blocs.append(data)
        elif blocs:
            break
    textes = []
    unique = struct.unpack("<I", [d for t, d in recs if t == 0x00FC][0][4:8])[0]
    b, p = 0, 0
    for _ in range(unique):
        if p >= len(blocs[b]):
            b, p = b + 1, 0
        cch, flag = struct.unpack("<HB", blocs[b][p:p + 3])
        p += 3
        reste, s = cch, ""
        largeur = 2 if flag & 1 else 1
        while True:
            dispo = (len(blocs[b]) - p) // largeur
            prendre = min(reste, dispo)
            s += blocs[b][p:p + prendre * largeur].decode("utf-16-le" if largeur == 2 else "latin-1")
            p += prendre * largeur
            reste -= prendre
            if not reste:
                break
            b, p = b + 1, 1
            largeur = 2 if blocs[b][0] & 1 else 1
        textes.append(s)
    noms = [d[8:].decode("utf-16-le") for t, d in recs if t == 0x0085]
    feuilles, courant = [], None
    eof = 0
    for t, d in recs:
        if t == 0x000A:
            eof += 1
            if eof > 1:
                feuilles.append(courant)
            courant = {}
            continue
        if eof == 0:
            continue
        if t in (0x00FD, 0x027E, 0x0203):
            r, c = struct.unpack("<HH", d[:4])
            if t == 0x00FD:
                v = textes[struct.unpack("<I", d[6:10])[0]]
            elif t == 0x0203:
                v = struct.unpack("<d", d[6:14])[0]
            else:
                rk = struct.unpack("<I", d[6:10])[0]
                v = (rk >> 2) - (1 << 30 if rk & 0x80000000 else 0) if rk & 2 else struct.unpack("<d", struct.pack("<Q", (rk & 0xFFFFFFFC) << 32))[0]
                if rk & 1:
                    v = v / 100
            courant[(r, c)] = v
    resultat = []
    for nom, cellules in zip(noms, feuilles):
        nl = max((r for r, _ in cellules), default=-1) + 1
        nc = max((c for _, c in cellules), default=-1) + 1
        resultat.append((nom, [[cellules[(r, c)] for c in range(nc) if (r, c) in cellules] for r in range(nl)]))
    return resultat, raw


def main():
    if len(sys.argv) >= 3 and sys.argv[1] == "--verifier":
        feuilles, raw = lire_xls(sys.argv[2])
        neuf = ole(classeur(feuilles))
        identique = neuf == raw
        print(f"{sys.argv[2]} : {sum(len(l) for _, l in feuilles)} lignes relues, "
              f"réécriture {'IDENTIQUE octet pour octet' if identique else 'DIFFÉRENTE'} ({len(neuf)} / {len(raw)} octets)")
        if not identique:
            premier = next(i for i in range(min(len(neuf), len(raw))) if neuf[i] != raw[i]) if len(neuf) and len(raw) else 0
            print(f"  premier octet différent : {premier}")
            sys.exit(1)
        return
    if len(sys.argv) not in (2, 3):
        sys.exit(__doc__)
    source = Path(sys.argv[1])
    donnees = json.loads(source.read_text(encoding="utf-8"))
    feuilles = [(f["nom"], f["lignes"]) for f in donnees["feuilles"]]
    sortie = Path(sys.argv[2]) if len(sys.argv) == 3 else source.with_suffix(".xls")
    sortie.write_bytes(ole(classeur(feuilles)))
    print(f"Fichier PC Compta : {sortie.name}")


if __name__ == "__main__":
    main()
