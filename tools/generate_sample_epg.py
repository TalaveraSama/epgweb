#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
EPGWeb — Generador de un XMLTV de ejemplo para pruebas.

Crea examples/sample-epg.xml con ~56 canales (nombres tomados de la guía real
del servidor Astra del cliente) y 3 días de programación en español.

Uso:
    python3 tools/generate_sample_epg.py            # escribe examples/sample-epg.xml
    python3 tools/generate_sample_epg.py - -        # escribe a stdout
"""

import datetime as dt
import random
import sys
from xml.sax.saxutils import escape

# Zona del XMLTV generado (Nicaragua, UTC-6)
TZOFFSET = "-0600"

random.seed(20261018)  # determinista: mismas salidas en cada ejecución

# ---------------------------------------------------------------- canales
# (xmltv_id, nombre, categoría) — nombres vistos en la guía real del cliente
CHANNELS = [
    # Nacionales Nicaragua
    ("Canal2.ni",     "Canal 2 - NIC",        "general"),
    ("Canal4.ni",     "Canal 4 - NIC",        "general"),
    ("Canal6.ni",     "Canal 6 - NIC",        "general"),
    ("Canal8.ni",     "Canal 8 - NIC",        "general"),
    ("Canal10.ni",    "Canal 10 HD",          "general"),
    ("Canal12.ni",    "Canal 12 - NIC",       "general"),
    ("Canal13.ni",    "CANAL 13 - NI",        "general"),
    ("Canal14.ni",    "Canal 14 - NIC",       "general"),
    ("NicaraoTV.ni",  "Nicarao TV",           "general"),
    ("TBNEnlace",     "TBN Enlace",           "religioso"),
    ("EWTN",          "EWTN",                 "religioso"),
    ("VozMaria",      "La Voz de Maria",      "religioso"),
    # Noticias
    ("RTInt",         "Russia Today Int",     "noticias"),
    ("France24",      "France24",             "noticias"),
    ("Antena3",       "Antena 3",             "noticias"),
    ("TVE",           "TVE",                  "noticias"),
    # Deportes
    ("ESPN2",         "ESPN 2",               "deportes"),
    ("ESPN3",         "ESPN 3",               "deportes"),
    ("ESPN4",         "ESPN 4 HD",            "deportes"),
    ("ESPN5",         "ESPN 5 HD",            "deportes"),
    ("ESPN6",         "ESPN 6",               "deportes"),
    ("PXTV",          "PX TV",                "deportes"),
    ("GolfChannel",   "Golf Channel HD",      "deportes"),
    # Películas y series
    ("HBO",           "HBO HD",               "cine"),
    ("HBO2",          "HBO2 HD",              "cine"),
    ("HBOPerfil",     "HBO Plus HD",          "cine"),
    ("HBOFamily",     "HBO Family HD",        "cine"),
    ("HBOXtreme",     "HBO Xtreme",           "cine"),
    ("Cinemax",       "CineMax",              "cine"),
    ("Space",         "SPACE HD",             "cine"),
    ("Cinecanal",     "CINECANAL HD",         "cine"),
    ("StudioUni",     "Studio Universal HD",  "cine"),
    ("Golden",        "Golden",               "cine"),
    ("TCM",           "TCM",                  "cine"),
    ("SonyMovies",    "Sony Movies HD",       "cine"),
    ("StarChannel",   "STAR CHANNEL HD",      "cine"),
    ("WarnerHD",      "WARNER HD",            "cine"),
    ("UniversalPrem", "UNIVERSAL PREMIERE HD", "cine"),
    ("AMC",           "AMC HD",               "cine"),
    ("AMCSeries",     "AMC Series",           "series"),
    ("TNTSeries",     "TNT Series",           "series"),
    ("TNTNovelas",    "TNT Novelas",          "series"),
    ("AXN",           "AXN HD",               "series"),
    ("Sony",          "SONY",                 "series"),
    # Infantil
    ("CartoonNet",    "CARTOON NETWORK HD",   "infantil"),
    ("DisneyJR",      "Disney JR",            "infantil"),
    ("Cartoonito",    "Cartoonito",           "infantil"),
    ("BabyTV",        "Baby TV",              "infantil"),
    ("ZooMoo",        "ZooMoo",               "infantil"),
    ("Tooncast",      "Tooncast",             "infantil"),
    ("Semillitas",    "SEMILLITAS",           "infantil"),
    # Cultural / documentales
    ("Discovery",     "Discovery Channel",    "documental"),
    ("AnimalPlanet",  "Animal Planet",        "documental"),
    ("History",       "History HD",           "documental"),
    ("H2",            "H2",                   "documental"),
    ("NatGeo",        "National Geographic",  "documental"),
    ("TLC",           "TLC",                  "documental"),
    ("ElGourmet",     "El Gourmet",           "documental"),
    # Variedades
    ("E!",            "E! Entertairment HD",  "variedades"),
    ("Azmundo",       "Azmundo",              "variedades"),
    ("CLAN",          "CLAN",                 "variedades"),
]

CAT_MAP = {
    "general":     "News", "religioso": "Religion", "noticias": "News",
    "deportes":    "Sports", "cine": "Movie/Drama", "series": "Show",
    "infantil":    "Children's/Youth", "documental": "Documentary",
    "variedades":  "Show",
}

# ------------------------------------------------------------- contenidos
NEWS_SHOWS = [
    ("Acción 10 Edición Estelar", "Las principales noticias de la agenda nacional e internacional."),
    ("Noticiero Matutino", "Resumen de los acontecimientos más relevantes del día en Nicaragua."),
    ("Micro Noticiero", "Breve repaso de las noticias de la hora."),
    ("Deportes en Acción", "Todo el acontecer deportivo nacional e internacional."),
    ("Café con Noticias", "Actualidad, entrevistas y análisis a fondo."),
]

TALK_SHOWS = [
    ("Buenos Días Nicaragua", "Magazine matutino con salud, cocina, farándula y servicios."),
    ("De Cara al País", "Entrevistas y análisis político con invitados especiales."),
    ("Voces Reales", "Historias de esfuerzo y superación de nicaragüenses."),
    ("Laura sin Censura", "Casos familiares con soluciones de la doctora Laura Bozzo."),
    ("Caso Cerrado", "La abogada Ana María Polo resuelve casos en corte."),
]

MOVIES = [
    ("El Padrino", "drama", "EE. UU.", 1972, "La saga de la familia Corleone y su imperio del crimen organizado en Nueva York."),
    ("Coco", "animación", "México", 2017, "Miguel viaja a la Tierra de los Muertos para descubrir el misterio de su familia."),
    ("Relatos Salvajes", "comedia", "Argentina", 2014, "Seis relatos donde la violencia y el descontrol sacan lo peor de las personas."),
    ("No Se Aceptan Devoluciones", "comedia", "México", 2013, "Valentín se convierte en papá soltero y cruza fronteras por su hija."),
    ("Jurassic Park", "aventura", "EE. UU.", 1993, "Un parque temático con dinosaurios clonados sale del control."),
    ("El Secreto de sus Ojos", "drama", "Argentina", 2009, "Un agente judicial obsesionado con un caso sin resolver durante décadas."),
    ("Mad Max: Furia en la Carretera", "acción", "Australia", 2015, "En un mundo postapocalíptico, Max y Furiosa huyen de un tirano."),
    ("Spider-Man: Un Nuevo Universo", "animación", "EE. UU.", 2018, "Miles Morales descubre que puede ser el nuevo Spider-Man."),
    ("Roma", "drama", "México", 2018, "La vida de una empleada doméstica en la Ciudad de México de los años 70."),
    ("Dunkerque", "bélica", "Reino Unido", 2017, "La evacuación de las tropas aliadas en la Segunda Guerra Mundial."),
    ("La Forma del Agua", "fantasía", "EE. UU.", 2017, "Una limpiadora de un laboratorio entabla un vínculo con una criatura acuática."),
    ("Instrucciones no Incluidas", "comedia", "México", 2013, "Un acrobata de Acapulco cria solo a una niña abandonada por su madre."),
    ("Titanic", "drama", "EE. UU.", 1997, "Un romance imposible a bordo del transatlántico más famoso del mundo."),
    ("Toy Story", "animación", "EE. UU.", 1995, "Los juguetes de Andy cobran vida cuando nadie los ve."),
    ("Cantinflas: El Bolero de la Vida", "comedia", "México", 1992, "Homenaje a los grandes éxitos del cómico mexicano Mario Moreno."),
    ("Gladiador", "acción", "EE. UU.", 2000, "Un general romano traicionado busca venganza como gladiador."),
]

SERIES = [
    ("La Rosa de Guadalupe", "Drama", "México", 2008, "Historias de fe y milagros de la Virgen de Guadalupe."),
    ("Como dice el Dicho", "Drama", "México", 2011, "Dichos populares llevados a historias de la vida real."),
    ("El Señor de los Cielos", "Serie", "México", 2013, "La historia de un capo que desafía al gobierno mexicano."),
    ("Marlon", "Comedia", "EE. UU.", 2017, "Un padre divertido aprende a criar a sus dos hijos."),
    ("The Rookie", "Drama", "EE. UU.", 2018, "Nolan deja su vida pueblerina para ser policía en Los Ángeles."),
    ("Agente Carter", "Serie", "EE. UU.", 2015, "La agente Peggy Carter defiende el mundo posguerra."),
    ("Hudson & Rex", "Drama", "Canadá", 2019, "Un detective forma equipo con un pastor alemán K9."),
    ("Comisario Rex", "Serie", "Austria", 1994, "Un perro pastor ayuda a resolver casos en Viena."),
    ("Guerrero Ninja Americano", "Concursos", "EE. UU.", 2009, "Cientos de concursantes superan obstáculos extremos."),
    ("Alerta Cobra", "Serie", "Alemania", 1996, "Los detectives Gerkhan y Jäger luchan contra el crimen en la autobahn."),
]

NOVELAS = [
    ("Piezas Rotas", "Dos jóvenes intercambiadas al nacer descubren la verdad quince años después."),
    ("Cesur, Amor y Venganza", "Un extraño salva la vida de Sühan y cambia el destino de los Korludag."),
    ("La Herencia, un Legado de Amor", "La lectura de un testamento cambia la vida de cinco hermanos adoptivos."),
    ("La Rosa de Guadalupe: Especiales", "Historias conmovedoras de fe y superación."),
]

KIDS_SHOWS = [
    ("Beyblade Burst", "Valt Aoi sueña con ser el blader número uno.", "infantil"),
    ("Grizzy y los Lemmings", "Un oso gruñón contra unos lemings hiperactivos.", "infantil"),
    ("Dragones: Carreras al Borde", "Hipo y Desdentao exploran nuevas fronteras.", "infantil"),
    ("Peppa Pig", "Las aventuras de una cerdita curiosa y su familia.", "preescolar"),
    ("Baby TV: Canciones", "Canciones y juegos para los más pequeños.", "preescolar"),
    ("ZooMoo: Salvaje", "Conociendo a los animales más sorprendentes del planeta.", "preescolar"),
    ("Looney Tunes", "Los cortos clásicos de Bugs Bunny y compañía.", "infantil"),
    ("We Bare Bears", "Tres osos adaptan su vida a la ciudad.", "infantil"),
]

DOCS = [
    ("Récords Guinness Oficialmente Asombrosos", "Los récords más increíbles de la historia."),
    ("Planeta Extremo", "Los hábitats más hostiles del planeta y sus habitantes."),
    ("Sobrevivir a la Selva", "Un experto demuestra técnicas de supervivencia extrema."),
    ("Fábrica de Autos", "Cómo se construyen los vehículos más avanzados del mundo."),
    ("Misterios del Pasado", "Arqueología e historia de las grandes civilizaciones."),
    ("Sabores de Nicaragua", "Un recorrido por la cocina tradicional nicaragüense."),
    ("Investigación Criminal", "Casos reales resueltos por la ciencia forense."),
]

SPORTS = [
    ("Fútbol: Liga Concacaf", "En vivo: fase de grupos, los mejores clubes de la región."),
    ("MLB: Resumen de la Jornada", "Lo mejor del béisbol de grandes ligas."),
    ("Boxeo: Combate Estelar", "Pelea por el título mundial desde Las Vegas."),
    ("SportsCenter", "Toda la información deportiva del día."),
    ("Fórmula 1: GP", "Clasificación del Gran Premio en vivo."),
    ("NBA: Doble Jornada", "Los mejores partidos de la semana."),
]

RELIGIOUS = [
    ("Santa Misa", "Celebración eucarística en vivo."),
    ("Cantando con Fe", "Música cristiana y reflexión."),
    ("Palabra Viva", "Estudio bíblico y oración."),
    ("Enlace Noticias", "Actualidad del mundo cristiano."),
]


def dtt(t):
    return t.strftime("%Y%m%d%H%M%S " + TZOFFSET)


def build():
    out = []
    ap = out.append
    ap('<?xml version="1.0" encoding="UTF-8"?>')
    ap('<tv generator-info-name="EPGWeb ejemplo" source-info-name="Guía demo (basada en Astra)">')
    for cid, name, _cat in CHANNELS:
        ap(f'  <channel id="{cid}">')
        ap(f'    <display-name lang="es">{escape(name)}</display-name>')
        ap('</channel>')

    today = dt.date.today()
    days = 3
    for cid, name, cat in CHANNELS:
        for d in range(days):
            day = today + dt.timedelta(days=d)
            day_start = dt.datetime.combine(day, dt.time(0, 0))
            day_end = day_start + dt.timedelta(days=1)
            t = day_start
            while t < day_end:
                start = t
                # elección de contenido según categoría y horario
                if cat in ("general", "noticias"):
                    if 6 <= t.hour < 9:
                        item = random.choice(NEWS_SHOWS + TALK_SHOWS); dur = 60
                    elif 12 <= t.hour < 14 or 19 <= t.hour < 21:
                        item = random.choice(NEWS_SHOWS); dur = 60
                    elif 22 <= t.hour:
                        item = random.choice(MOVIES); dur = 120
                    else:
                        item = random.choice(TALK_SHOWS + SERIES); dur = 60
                elif cat == "deportes":
                    item = random.choice(SPORTS); dur = 90 if "Fútbol" in item[0] or "Boxeo" in item[0] else 60
                elif cat == "cine":
                    item = random.choice(MOVIES); dur = random.choice((105, 120, 135, 150))
                elif cat == "series":
                    item = random.choice(SERIES); dur = 60
                elif cat == "infantil":
                    if 21 <= t.hour or t.hour < 6:
                        item = ("Infinito Kids", "Bloque nocturno con series para toda la familia."); dur = 120
                    else:
                        item = random.choice(KIDS_SHOWS); dur = 30
                elif cat == "documental":
                    item = random.choice(DOCS); dur = 60
                elif cat == "variedades":
                    if 20 <= t.hour < 23:
                        item = random.choice(MOVIES); dur = 120
                    else:
                        item = random.choice(TALK_SHOWS + DOCS); dur = 60
                elif cat == "religioso":
                    item = random.choice(RELIGIOUS); dur = 60
                else:
                    item = random.choice(TALK_SHOWS); dur = 60

                t = t + dt.timedelta(minutes=dur)
                stop = min(t, day_end)   # el último bloque cierra en la medianoche

                if len(item) == 5:  # película (título, género, país, año, desc)
                    title, genre, country, year, desc = item
                    extra = [
                        '      <desc lang="es">' + escape(desc) + ' Actors: ; Director: ; Country: ' + country + '; Year: ' + str(year) + ';</desc>',
                        '      <category lang="es">Movie/Drama</category>',
                        '      <country lang="es">' + country + '</country>',
                    ]
                else:
                    title, desc = item[0], item[1]
                    extra = [
                        '      <desc lang="es">' + escape(desc) + '</desc>',
                        '      <category lang="es">' + escape(CAT_MAP.get(cat, "Show")) + '</category>',
                    ]
                    # episodio para series
                    if cat == "series" and random.random() < 0.8:
                        ep = 'T' + str(random.randint(1, 6)) + ' E' + str(random.randint(1, 22))
                        extra.append('      <episode-num system="onscreen">' + ep + '</episode-num>')

                ap('  <programme start="' + dtt(start) + '" stop="' + dtt(stop) + '" channel="' + cid + '">')
                ap('    <title lang="es">' + escape(title) + '</title>')
                for line in extra:
                    ap(line)
                ap('</programme>')

    ap('</tv>')
    return "\n".join(out)


if __name__ == "__main__":
    xml = build()
    if len(sys.argv) > 1 and sys.argv[1] == "-":
        sys.stdout.write(xml)
    else:
        path = __file__.rsplit("/", 2)[0] + "/examples/sample-epg.xml"
        with open(path, "w", encoding="utf-8") as f:
            f.write(xml)
        n_prog = xml.count("<programme ")
        print(f"OK: {path} ({len(xml) / 1024:.0f} KB, {len(CHANNELS)} canales, {n_prog} programas)")
