#!/usr/bin/env python3
"""Extract a TAR.GZ beside itself after checking every member and destination."""

import json
import os
import sys
import tarfile


def emit(payload):
    print(json.dumps(payload, ensure_ascii=False))


def safe_parts(name):
    if not name or name.startswith(("/", "\\")) or "\\" in name or "\x00" in name:
        raise ValueError("El archivo comprimido contiene una ruta insegura.")
    parts = [part for part in name.rstrip("/").split("/") if part and part != "."]
    if not parts or ".." in parts or ":" in parts[0]:
        raise ValueError("El archivo comprimido contiene una ruta insegura.")
    return parts


def check_parents(root, parts):
    current = root
    for part in parts[:-1]:
        current = os.path.join(current, part)
        if os.path.lexists(current) and (os.path.islink(current) or not os.path.isdir(current)):
            raise ValueError("La carpeta destino contiene un enlace o archivo incompatible.")


def main():
    archive, overwrite = sys.argv[1], sys.argv[2] == "1"
    root = os.path.dirname(os.path.realpath(archive))
    with tarfile.open(archive, "r:gz") as source:
        members = source.getmembers()
        if len(members) > 100000:
            raise ValueError("El archivo comprimido supera el límite seguro de 100 000 elementos.")
        total = 0
        conflicts = []
        paths = []
        for member in members:
            parts = safe_parts(member.name)
            if not (member.isfile() or member.isdir()):
                raise ValueError("El archivo comprimido contiene enlaces o tipos no admitidos.")
            total += member.size
            if total > 2 * 1024 * 1024 * 1024:
                raise ValueError("El contenido descomprimido supera el límite seguro de 2 GB.")
            check_parents(root, parts)
            target = os.path.join(root, *parts)
            if os.path.islink(target):
                raise ValueError("La carpeta destino contiene un enlace simbólico.")
            if os.path.lexists(target):
                if member.isdir() and not os.path.isdir(target):
                    raise ValueError("La carpeta destino contiene un archivo incompatible.")
                if member.isfile():
                    conflicts.append("/".join(parts))
            paths.append((member, parts, target))
        if conflicts and not overwrite:
            emit({"status": "conflict", "conflicts": conflicts[:50], "conflict_count": len(conflicts)})
            return
        for member, parts, target in paths:
            check_parents(root, parts)
            parent = root
            for part in parts[:-1]:
                parent = os.path.join(parent, part)
                if not os.path.isdir(parent):
                    os.mkdir(parent, 0o750)
            if member.isdir():
                if not os.path.isdir(target):
                    os.mkdir(target, 0o750)
                continue
            if os.path.lexists(target) and (os.path.islink(target) or not os.path.isfile(target)):
                raise ValueError("El archivo destino cambió durante la extracción.")
            extracted = source.extractfile(member)
            if extracted is None:
                raise ValueError("No se pudo leer un archivo comprimido.")
            flags = os.O_WRONLY | os.O_CREAT | (os.O_TRUNC if overwrite else os.O_EXCL)
            flags |= getattr(os, "O_NOFOLLOW", 0)
            fd = os.open(target, flags, 0o640)
            with os.fdopen(fd, "wb") as output, extracted:
                while chunk := extracted.read(1024 * 1024):
                    output.write(chunk)
        emit({"status": "extracted", "count": len(members)})


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, tarfile.TarError) as error:
        emit({"status": "error", "message": str(error)})
        sys.exit(1)
