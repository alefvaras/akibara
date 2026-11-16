#!/usr/bin/env python3
"""Regenera todas las portadas de los nuevos tomos agregados desde sus imágenes originales
usando Akibara Image Studio (preset Auto: trim, upscale x4 GPU Real-ESRGAN, resize 800px, AVIF target_bpp 0.116).

Posteriormente:
1. Actualiza WordPress local con metadatos completos (título, alt, caption, descripción),
   ejecuta wp_generate_attachment_metadata() para crear las 8 dimensiones responsive y srcset,
   y asocia la imagen destacada al producto.
2. Despliega las 4 portadas de producción (One Punch-Man 36, 37, Berserk Master Edition 02, Predicador 03)
   a akibara.cl, regenera metadatos y sub-tamaños en producción, y purga LiteSpeed Cache.
"""

from __future__ import annotations

import json
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

STUDIO_URL = "http://127.0.0.1:8420/api/process"
STUDIO_OUT_DIR = Path("/Users/alefvaras/Documents/akibara-image-studio/out")
LOCAL_UPLOADS = Path("/Users/alefvaras/Documents/akibara/public_html/wp-content/uploads/2026/10")
LOCAL_UPLOADS.mkdir(parents=True, exist_ok=True)

# Catálogo completo de los 36 tomos nuevos
CATALOGO = [
    # --- GRUPO 1: PRODUCCIÓN Y LOCAL (4 TOMOS) ---
    {
        "slug": "one-punch-man-36-ivrea-espana",
        "raw_path": "/tmp/opm_test/onepunchman36.jpg",
        "local_pid": 32718,
        "local_att": 32720,
        "prod_pid": 28708,
        "prod_att": 28704,
        "is_prod": True,
        "title": "One Punch-Man 36 - Editorial Ivrea España",
        "alt": "Portada oficial de One Punch-Man 36 en español - Editorial Ivrea España",
        "caption": "Portada del tomo 36 de One Punch-Man, editado por Ivrea España.",
        "desc": "Fotografía y portada oficial en alta resolución del tomo 36 de One Punch-Man por ONE y Yusuke Murata, publicado por Editorial Ivrea España. Disponible en Akibara con envío a todo Chile.",
    },
    {
        "slug": "one-punch-man-37-ivrea-espana",
        "raw_path": "/tmp/opm_test/onepunchman37.jpg",
        "local_pid": 32719,
        "local_att": 32721,
        "prod_pid": 28709,
        "prod_att": 28705,
        "is_prod": True,
        "title": "One Punch-Man 37 - Editorial Ivrea España",
        "alt": "Portada oficial de One Punch-Man 37 en español - Editorial Ivrea España",
        "caption": "Portada del tomo 37 de One Punch-Man, editado por Ivrea España.",
        "desc": "Fotografía y portada oficial en alta resolución del tomo 37 de One Punch-Man por ONE y Yusuke Murata, publicado por Editorial Ivrea España. Disponible en Akibara con envío a todo Chile.",
    },
    {
        "slug": "berserk-master-edition-02-panini-espana",
        "raw_path": "/tmp/covers_test/berserk_02_raw.jpg",
        "local_pid": 32724,
        "local_att": 32722,
        "prod_pid": 28706,
        "prod_att": 28702,
        "is_prod": True,
        "title": "Berserk Master Edition 02 - Panini España",
        "alt": "Portada oficial de Berserk Master Edition 02 en español - Panini España",
        "caption": "Portada de Berserk Master Edition tomo 2 en formato de gran tamaño cartoné, Panini España.",
        "desc": "Fotografía y portada oficial en alta resolución de Berserk Master Edition 02 de Kentaro Miura, publicada por Panini Cómics España en formato de lujo. Disponible en Akibara con envío a todo Chile.",
    },
    {
        "slug": "predicador-3-panini-espana",
        "raw_path": "/tmp/covers_test/predicador_03_raw.jpg",
        "local_pid": 32725,
        "local_att": 32723,
        "prod_pid": 28707,
        "prod_att": 28703,
        "is_prod": True,
        "title": "Predicador 03 - Panini España",
        "alt": "Portada oficial de Predicador 03 en español - Panini España",
        "caption": "Portada de Predicador tomo 3 en formato cartoné de lujo, Panini España.",
        "desc": "Fotografía y portada oficial en alta resolución de Predicador volumen 3 de Garth Ennis y Steve Dillon, publicado por Panini Cómics España. Disponible en Akibara con envío a todo Chile.",
    },

    # --- GRUPO 2: MILKY WAY (10 TOMOS LOCAL) ---
    {
        "slug": "atelier-of-witch-hat-edicion-grimorio-vol-4-milky-way",
        "raw_path": "/tmp/mw_covers/out/atelier-of-witch-hat-edicion-grimorio-vol-4_raw.jpg",
        "local_pid": 32727,
        "local_att": 32726,
        "is_prod": False,
        "title": "Atelier of Witch Hat (Edición Grimorio), Vol. 04 - Milky Way Ediciones",
        "alt": "Portada oficial de Atelier of Witch Hat Grimorio 04 en español - Milky Way Ediciones",
        "caption": "Portada de Atelier of Witch Hat Edición Grimorio tomo 4 en tapa dura con estampados dorados.",
        "desc": "Fotografía y portada oficial en alta resolución de Atelier of Witch Hat Edición Grimorio 4 de Kamome Shirahama, publicada por Milky Way Ediciones en gran formato cartoné.",
    },
]

# Agregar La nobleza de las flores 12 al 20
for v in range(12, 21):
    CATALOGO.append({
        "slug": f"la-nobleza-de-las-flores-{v}-milky-way",
        "raw_path": f"/tmp/mw_covers/out/la-nobleza-de-las-flores-vol-{v}_raw.jpg",
        "local_pid": 32729 + (v - 12) * 2,
        "local_att": 32728 + (v - 12) * 2,
        "is_prod": False,
        "title": f"La nobleza de las flores {v} - Milky Way Ediciones",
        "alt": f"Portada oficial de La nobleza de las flores {v} en español - Milky Way Ediciones",
        "caption": f"Portada del tomo {v} de La nobleza de las flores, publicado por Milky Way Ediciones.",
        "desc": f"Fotografía y portada oficial en alta resolución de La nobleza de las flores tomo {v} de Saka Mikami, publicado por Milky Way Ediciones con sobrecubierta de tacto especial.",
    })

# --- GRUPO 3: PANINI / VERTIGO (7 TOMOS LOCAL) ---
CATALOGO.extend([
    {
        "slug": "la-cosa-del-pantano-de-alan-moore-2-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/la-cosa-del-pantano-de-alan-moore-2-panini-espana_raw.jpg",
        "local_pid": 32747,
        "local_att": 32746,
        "is_prod": False,
        "title": "La Cosa del Pantano de Alan Moore 02 - Panini España",
        "alt": "Portada oficial de La Cosa del Pantano 02 de Alan Moore en español - Panini España",
        "caption": "Portada de La Cosa del Pantano volumen 2 de Alan Moore en encuadernación cartoné.",
        "desc": "Fotografía y portada oficial en alta resolución de La Cosa del Pantano 2 de Alan Moore, Stephen Bissette y John Totleben, publicada por Panini Cómics España.",
    },
    {
        "slug": "la-cosa-del-pantano-de-alan-moore-3-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/la-cosa-del-pantano-de-alan-moore-3-panini-espana_raw.jpg",
        "local_pid": 32749,
        "local_att": 32748,
        "is_prod": False,
        "title": "La Cosa del Pantano de Alan Moore 03 - Panini España",
        "alt": "Portada oficial de La Cosa del Pantano 03 de Alan Moore en español - Panini España",
        "caption": "Portada de La Cosa del Pantano volumen 3 de Alan Moore en encuadernación cartoné.",
        "desc": "Fotografía y portada oficial en alta resolución de La Cosa del Pantano 3 de Alan Moore, Stephen Bissette y John Totleben, publicada por Panini Cómics España.",
    },
    {
        "slug": "fumando-juntos-detras-del-super-09-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/fumando-juntos-detras-del-super-09-panini-espana_raw.jpg",
        "local_pid": 32751,
        "local_att": 32750,
        "is_prod": False,
        "title": "Fumando juntos detrás del súper 09 - Panini España",
        "alt": "Portada oficial de Fumando juntos detrás del súper 09 en español - Panini España",
        "caption": "Portada del tomo 9 de Fumando juntos detrás del súper, Panini Manga España.",
        "desc": "Fotografía y portada oficial en alta resolución de Fumando juntos detrás del súper tomo 9 de Jinushi, publicado por Panini Manga España.",
    },
    {
        "slug": "el-chico-que-me-gusta-no-es-un-chico-3-especial-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/el-chico-que-me-gusta-no-es-un-chico-3-especial-panini-espana_raw.jpg",
        "local_pid": 32753,
        "local_att": 32752,
        "is_prod": False,
        "title": "El chico que me gusta no es un chico 03 (Edición Especial) - Panini España",
        "alt": "Portada oficial de El chico que me gusta no es un chico 03 Edición Especial - Panini España",
        "caption": "Portada de El chico que me gusta no es un chico tomo 3 especial con púa y colgante, Panini España.",
        "desc": "Fotografía y portada oficial en alta resolución de El chico que me gusta no es un chico tomo 3 Edición Especial de Sumiko Arai, publicada por Panini Manga España.",
    },
    {
        "slug": "biblioteca-vertigo-john-constantine-hellblazer-13-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/biblioteca-vertigo-john-constantine-hellblazer-13-panini-espana_raw.jpg",
        "local_pid": 32755,
        "local_att": 32754,
        "is_prod": False,
        "title": "John Constantine: Hellblazer (Biblioteca Vertigo) 13 - Panini España",
        "alt": "Portada oficial de John Constantine Hellblazer 13 Biblioteca Vertigo - Panini España",
        "caption": "Portada de Hellblazer 13 en formato cartoné, Panini Cómics España.",
        "desc": "Fotografía y portada oficial en alta resolución de John Constantine: Hellblazer 13 de Garth Ennis y Steve Dillon, publicado por Panini Cómics España.",
    },
    {
        "slug": "biblioteca-vertigo-john-constantine-hellblazer-14-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/biblioteca-vertigo-john-constantine-hellblazer-14-panini-espana_raw.jpg",
        "local_pid": 32757,
        "local_att": 32756,
        "is_prod": False,
        "title": "John Constantine: Hellblazer (Biblioteca Vertigo) 14 - Panini España",
        "alt": "Portada oficial de John Constantine Hellblazer 14 Biblioteca Vertigo - Panini España",
        "caption": "Portada de Hellblazer 14 en formato cartoné, Panini Cómics España.",
        "desc": "Fotografía y portada oficial en alta resolución de John Constantine: Hellblazer 14 de Garth Ennis y Steve Dillon, publicado por Panini Cómics España.",
    },
    {
        "slug": "biblioteca-vertigo-john-constantine-hellblazer-15-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/biblioteca-vertigo-john-constantine-hellblazer-15-panini-espana_raw.jpg",
        "local_pid": 32759,
        "local_att": 32758,
        "is_prod": False,
        "title": "John Constantine: Hellblazer (Biblioteca Vertigo) 15 - Panini España",
        "alt": "Portada oficial de John Constantine Hellblazer 15 Biblioteca Vertigo - Panini España",
        "caption": "Portada de Hellblazer 15 (Heartland) en formato cartoné, Panini Cómics España.",
        "desc": "Fotografía y portada oficial en alta resolución de John Constantine: Hellblazer 15 de Garth Ennis y Steve Dillon, publicado por Panini Cómics España.",
    },
    {
        "slug": "biblioteca-vertigo-john-constantine-hellblazer-16-panini-espana",
        "raw_path": "/tmp/cdn_covers/out/biblioteca-vertigo-john-constantine-hellblazer-16-panini-espana_raw.jpg",
        "local_pid": 32761,
        "local_att": 32760,
        "is_prod": False,
        "title": "John Constantine: Hellblazer (Biblioteca Vertigo) 16 - Panini España",
        "alt": "Portada oficial de John Constantine Hellblazer 16 Biblioteca Vertigo - Panini España",
        "caption": "Portada de Hellblazer 16 en formato cartoné, Panini Cómics España.",
        "desc": "Fotografía y portada oficial en alta resolución de John Constantine: Hellblazer 16 de Paul Jenkins y Sean Phillips, publicado por Panini Cómics España.",
    },
])

# --- GRUPO 4: ARECHI / PLANETA (14 TOMOS LOCAL) ---
CATALOGO.extend([
    {
        "slug": "gannibal-5-arechi-manga",
        "raw_path": "/tmp/cdn_covers/out/gannibal-5-arechi-manga_raw.jpg",
        "local_pid": 32763,
        "local_att": 32762,
        "is_prod": False,
        "title": "Gannibal 05 - Arechi Manga",
        "alt": "Portada oficial de Gannibal 05 en español - Arechi Manga",
        "caption": "Portada del tomo 5 de Gannibal, publicado por Arechi Manga.",
        "desc": "Fotografía y portada oficial en alta resolución de Gannibal tomo 5 de Masaaki Ninomiya, publicado por Arechi Manga España.",
    },
    {
        "slug": "naruto-jump-remix-15-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/naruto-jump-remix-15-planeta-espana_raw.jpg",
        "local_pid": 32765,
        "local_att": 32764,
        "is_prod": False,
        "title": "Naruto Jump Remix 15 - Planeta Cómic",
        "alt": "Portada oficial de Naruto Jump Remix 15 en español - Planeta Cómic",
        "caption": "Portada de Naruto Jump Remix volumen 15, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Naruto Jump Remix tomo 15 de Masashi Kishimoto, publicado por Planeta Cómic España.",
    },
    {
        "slug": "one-piece-14-3-en-1-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/one-piece-14-3-en-1-planeta-espana_raw.jpg",
        "local_pid": 32767,
        "local_att": 32766,
        "is_prod": False,
        "title": "One Piece 3 en 1 nº 14 - Planeta Cómic",
        "alt": "Portada oficial de One Piece 3 en 1 tomo 14 en español - Planeta Cómic",
        "caption": "Portada de One Piece 3 en 1 tomo 14, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de One Piece 3 en 1 tomo 14 de Eiichiro Oda, publicado por Planeta Cómic España.",
    },
    {
        "slug": "one-piece-15-3-en-1-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/one-piece-15-3-en-1-planeta-espana_raw.jpg",
        "local_pid": 32769,
        "local_att": 32768,
        "is_prod": False,
        "title": "One Piece 3 en 1 nº 15 - Planeta Cómic",
        "alt": "Portada oficial de One Piece 3 en 1 tomo 15 en español - Planeta Cómic",
        "caption": "Portada de One Piece 3 en 1 tomo 15, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de One Piece 3 en 1 tomo 15 de Eiichiro Oda, publicado por Planeta Cómic España.",
    },
    {
        "slug": "hajime-no-ippo-14-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/hajime-no-ippo-14-planeta-espana_raw.jpg",
        "local_pid": 32771,
        "local_att": 32770,
        "is_prod": False,
        "title": "Hajime no Ippo 14 - Planeta Cómic",
        "alt": "Portada oficial de Hajime no Ippo 14 en español - Planeta Cómic",
        "caption": "Portada del tomo 14 de Hajime no Ippo, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Hajime no Ippo tomo 14 de George Morikawa, publicado por Planeta Cómic España.",
    },
    {
        "slug": "hajime-no-ippo-15-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/hajime-no-ippo-15-planeta-espana_raw.jpg",
        "local_pid": 32773,
        "local_att": 32772,
        "is_prod": False,
        "title": "Hajime no Ippo 15 - Planeta Cómic",
        "alt": "Portada oficial de Hajime no Ippo 15 en español - Planeta Cómic",
        "caption": "Portada del tomo 15 de Hajime no Ippo, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Hajime no Ippo tomo 15 de George Morikawa, publicado por Planeta Cómic España.",
    },
    {
        "slug": "hajime-no-ippo-16-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/hajime-no-ippo-16-planeta-espana_raw.jpg",
        "local_pid": 32775,
        "local_att": 32774,
        "is_prod": False,
        "title": "Hajime no Ippo 16 - Planeta Cómic",
        "alt": "Portada oficial de Hajime no Ippo 16 en español - Planeta Cómic",
        "caption": "Portada del tomo 16 de Hajime no Ippo, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Hajime no Ippo tomo 16 de George Morikawa, publicado por Planeta Cómic España.",
    },
    {
        "slug": "hajime-no-ippo-17-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/hajime-no-ippo-17-planeta-espana_raw.jpg",
        "local_pid": 32777,
        "local_att": 32776,
        "is_prod": False,
        "title": "Hajime no Ippo 17 - Planeta Cómic",
        "alt": "Portada oficial de Hajime no Ippo 17 en español - Planeta Cómic",
        "caption": "Portada del tomo 17 de Hajime no Ippo, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Hajime no Ippo tomo 17 de George Morikawa, publicado por Planeta Cómic España.",
    },
    {
        "slug": "hajime-no-ippo-18-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/hajime-no-ippo-18-planeta-espana_raw.jpg",
        "local_pid": 32779,
        "local_att": 32778,
        "is_prod": False,
        "title": "Hajime no Ippo 18 - Planeta Cómic",
        "alt": "Portada oficial de Hajime no Ippo 18 en español - Planeta Cómic",
        "caption": "Portada del tomo 18 de Hajime no Ippo, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Hajime no Ippo tomo 18 de George Morikawa, publicado por Planeta Cómic España.",
    },
    {
        "slug": "invencible-4-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/invencible-4-planeta-espana_raw.jpg",
        "local_pid": 32781,
        "local_att": 32780,
        "is_prod": False,
        "title": "Invencible 04 - Planeta Cómic",
        "alt": "Portada oficial de Invencible 04 en español - Planeta Cómic",
        "caption": "Portada de Invencible volumen 4 integral en tapa dura cartoné, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de Invencible volumen 4 de Robert Kirkman y Ryan Ottley, publicado por Planeta Cómic España.",
    },
    {
        "slug": "the-walking-dead-5-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/the-walking-dead-5-planeta-espana_raw.jpg",
        "local_pid": 32783,
        "local_att": 32782,
        "is_prod": False,
        "title": "The Walking Dead 05 - Planeta Cómic",
        "alt": "Portada oficial de The Walking Dead 05 en español - Planeta Cómic",
        "caption": "Portada de The Walking Dead tomo 5 integral en cartoné, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de The Walking Dead tomo 5 de Robert Kirkman y Charlie Adlard, publicado por Planeta Cómic España en encuadernación cartoné.",
    },
    {
        "slug": "the-walking-dead-6-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/the-walking-dead-6-planeta-espana_raw.jpg",
        "local_pid": 32785,
        "local_att": 32784,
        "is_prod": False,
        "title": "The Walking Dead 06 - Planeta Cómic",
        "alt": "Portada oficial de The Walking Dead 06 en español - Planeta Cómic",
        "caption": "Portada de The Walking Dead tomo 6 integral en cartoné, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de The Walking Dead tomo 6 de Robert Kirkman y Charlie Adlard, publicado por Planeta Cómic España en encuadernación cartoné.",
    },
    {
        "slug": "the-walking-dead-7-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/the-walking-dead-7-planeta-espana_raw.jpg",
        "local_pid": 32787,
        "local_att": 32786,
        "is_prod": False,
        "title": "The Walking Dead 07 - Planeta Cómic",
        "alt": "Portada oficial de The Walking Dead 07 en español - Planeta Cómic",
        "caption": "Portada de The Walking Dead tomo 7 integral en cartoné, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución de The Walking Dead tomo 7 de Robert Kirkman y Charlie Adlard, publicado por Planeta Cómic España en encuadernación cartoné.",
    },
    {
        "slug": "vinland-saga-29-planeta-espana",
        "raw_path": "/tmp/cdn_covers/out/vinland-saga-29-planeta-espana_raw.jpg",
        "local_pid": 32789,
        "local_att": 32788,
        "is_prod": False,
        "title": "Vinland Saga 29 - Planeta Cómic",
        "alt": "Portada oficial de Vinland Saga 29 en español - Planeta Cómic",
        "caption": "Portada del tomo final 29 de Vinland Saga, Planeta Cómic España.",
        "desc": "Fotografía y portada oficial en alta resolución del tomo final 29 de Vinland Saga de Makoto Yukimura, publicado por Planeta Cómic España.",
    },
])


def process_with_studio(raw_path: str, filename_slug: str) -> Path:
    """Envía la imagen original a Akibara Image Studio con preset Auto."""
    boundary = "----WebKitFormBoundaryAkibaraImageStudioAuto"
    with open(raw_path, "rb") as f:
        file_bytes = f.read()

    fields = {
        "trim": "true",
        "upscale": "true",
        "resize": "true",
        "max_width": "800",
        "max_height": "0",
        "required_min_width": "800",
        "out_format": "avif",
        "target_bpp": "0.116",
        "quality": "92",
    }

    body = bytearray()
    for k, v in fields.items():
        body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode("utf-8"))

    body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename_slug}.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode("utf-8"))
    body.extend(file_bytes)
    body.extend(f"\r\n--{boundary}--\r\n".encode("utf-8"))

    req = urllib.request.Request(
        STUDIO_URL,
        data=bytes(body),
        headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "User-Agent": "AkibaraImageStudioClient/1.0",
        },
    )

    with urllib.request.urlopen(req) as resp:
        res = json.loads(resp.read().decode("utf-8"))
        if not res.get("ok"):
            raise RuntimeError(f"Error procesando {filename_slug}: {res.get('error')}")

        job_id = res["id"]
        out_file = STUDIO_OUT_DIR / job_id / f"{filename_slug}.avif"
        if not out_file.exists():
            # Buscar cualquier avif en la carpeta
            found = list((STUDIO_OUT_DIR / job_id).glob("*.avif"))
            if found:
                out_file = found[0]
            else:
                raise FileNotFoundError(f"No se encontró archivo generado para {filename_slug} en {STUDIO_OUT_DIR / job_id}")

        return out_file


def main() -> None:
    print("=================================================================")
    print("REGENERACIÓN DE PORTADAS CON AKIBARA IMAGE STUDIO (PRESET AUTO)")
    print(f"Total de imágenes a procesar: {len(CATALOGO)}")
    print("=================================================================\n")

    # 1. Procesar todas las imágenes en Image Studio si no existen
    print("--- FASE 1: PROCESAMIENTO CON IMAGE STUDIO ---")
    for idx, item in enumerate(CATALOGO, 1):
        slug = item["slug"]
        raw_p = item["raw_path"]
        dest_avif = LOCAL_UPLOADS / f"{slug}.avif"

        if dest_avif.exists() and dest_avif.stat().st_size > 50000:
            print(f"[{idx}/{len(CATALOGO)}] Ya existe {dest_avif.name} ({dest_avif.stat().st_size} bytes), omitiendo procesamiento.")
            continue

        print(f"[{idx}/{len(CATALOGO)}] Procesando {slug} desde {Path(raw_p).name}...")
        t0 = time.time()
        out_path = process_with_studio(raw_p, slug)
        elapsed = time.time() - t0

        dest_avif.write_bytes(out_path.read_bytes())
        print(f"  -> Generado: {dest_avif.name} ({dest_avif.stat().st_size} bytes) en {elapsed:.2f}s")

    print("\n--- FASE 2: ACTUALIZAR WORDPRESS LOCAL (METADATOS + SUB-SIZES + ALT) ---")
    import base64
    b64_catalogo = base64.b64encode(json.dumps(CATALOGO).encode("utf-8")).decode("ascii")

    local_php_tpl = """<?php
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $catalogo = json_decode(base64_decode('__B64_CATALOGO__'), true);

    foreach ($catalogo as $item) {
        $slug = $item['slug'];
        $att_id = (int)$item['local_att'];
        $pid = (int)$item['local_pid'];
        $filename = $slug . '.avif';
        $file_path = wp_upload_dir()['basedir'] . '/2026/10/' . $filename;

        if (!file_exists($file_path)) {
            echo "ERROR: Archivo local no existe: $file_path\\n";
            continue;
        }

        // 1. Actualizar post del adjunto
        $update_data = array(
            'ID' => $att_id,
            'post_title' => $item['title'],
            'post_excerpt' => $item['caption'],
            'post_content' => $item['desc'],
        );
        wp_update_post($update_data);

        // 2. Alt text y archivo adjunto
        update_post_meta($att_id, '_wp_attachment_image_alt', $item['alt']);
        update_post_meta($att_id, '_wp_attached_file', '2026/10/' . $filename);

        // 3. Regenerar metadatos (dimensiones y todos los tamaños 100x, 150x, 300x, 768x, etc.)
        $metadata = wp_generate_attachment_metadata($att_id, $file_path);
        wp_update_attachment_metadata($att_id, $metadata);

        // 4. Asegurar que el producto tenga este thumbnail
        set_post_thumbnail($pid, $att_id);

        $w = $metadata['width'] ?? 'NO_W';
        $h = $metadata['height'] ?? 'NO_H';
        $sizes_count = count($metadata['sizes'] ?? array());
        echo "Local OK: Att $att_id (Prod $pid) | " . $item['title'] . " | {$w}x{$h} | {$sizes_count} sub-sizes\\n";
    }
    """
    local_php = local_php_tpl.replace("__B64_CATALOGO__", b64_catalogo)

    res_local = subprocess.run(
        ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"],
        input=local_php,
        text=True,
        capture_output=True,
    )
    if res_local.returncode != 0:
        print(f"Error actualizando WP local:\n{res_local.stderr}")
        sys.exit(1)
    print(res_local.stdout)

    print("\n--- FASE 3: DESPLIEGUE A PRODUCCIÓN DE LAS 4 PORTADAS ---")
    prod_items = [it for it in CATALOGO if it.get("is_prod")]
    print(f"Sincronizando {len(prod_items)} portadas de producción...")

    # Copiar archivos a producción vía SSH
    for item in prod_items:
        slug = item["slug"]
        local_f = LOCAL_UPLOADS / f"{slug}.avif"
        remote_rel = f"domains/akibara.cl/public_html/wp-content/uploads/2026/10/{slug}.avif"
        print(f"Enviando {slug}.avif a producción...")
        proc = subprocess.run(
            ["ssh", "akibara", f"cat > {remote_rel}"],
            input=local_f.read_bytes(),
            capture_output=True,
        )
        if proc.returncode != 0:
            print(f"Error copiando {slug} a prod:\n{proc.stderr.decode()}")
            sys.exit(1)

    b64_prod = base64.b64encode(json.dumps(prod_items).encode("utf-8")).decode("ascii")

    # Actualizar metadatos y sub-sizes en producción
    prod_php_tpl = """<?php
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $prod_items = json_decode(base64_decode('__B64_PROD__'), true);

    foreach ($prod_items as $item) {
        $slug = $item['slug'];
        $att_id = (int)$item['prod_att'];
        $pid = (int)$item['prod_pid'];
        $filename = $slug . '.avif';
        $file_path = wp_upload_dir()['basedir'] . '/2026/10/' . $filename;

        if (!file_exists($file_path)) {
            echo "ERROR PROD: Archivo no existe: $file_path\\n";
            continue;
        }

        // 1. Actualizar post del adjunto
        $update_data = array(
            'ID' => $att_id,
            'post_title' => $item['title'],
            'post_excerpt' => $item['caption'],
            'post_content' => $item['desc'],
        );
        wp_update_post($update_data);

        // 2. Alt text y archivo adjunto
        update_post_meta($att_id, '_wp_attachment_image_alt', $item['alt']);
        update_post_meta($att_id, '_wp_attached_file', '2026/10/' . $filename);

        // 3. Regenerar metadatos y tamaños
        $metadata = wp_generate_attachment_metadata($att_id, $file_path);
        wp_update_attachment_metadata($att_id, $metadata);

        // 4. Vincular miniatura del producto
        set_post_thumbnail($pid, $att_id);

        $w = $metadata['width'] ?? 'NO_W';
        $h = $metadata['height'] ?? 'NO_H';
        $sizes_count = count($metadata['sizes'] ?? array());
        echo "PROD OK: Att $att_id (Prod $pid) | " . $item['title'] . " | {$w}x{$h} | {$sizes_count} sub-sizes\\n";
    }
    """
    prod_php = prod_php_tpl.replace("__B64_PROD__", b64_prod)

    res_prod = subprocess.run(
        ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"],
        input=prod_php,
        text=True,
        capture_output=True,
    )
    if res_prod.returncode != 0:
        print(f"Error actualizando WP prod:\n{res_prod.stderr}")
        sys.exit(1)
    print(res_prod.stdout)

    print("\n--- FASE 4: PURGA DE CACHÉ EN PRODUCCIÓN ---")
    purge_res = subprocess.run(
        ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp litespeed-purge all"],
        capture_output=True,
        text=True,
    )
    print(purge_res.stdout)

    print("\n=== PROCESO COMPLETADO EXITOSAMENTE ===")


if __name__ == "__main__":
    main()
