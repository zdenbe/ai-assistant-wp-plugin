#!/bin/bash

# Cesta k aktuálnímu adresáři (root)
ROOT_DIR=$(pwd)

# Soubor pro soubory v rootu, mimo podadresářů
ROOT_OUTPUT_FILE="$ROOT_DIR/root.txt"

# Nejprve vyčisti soubor pro root, pokud již existuje
> "$ROOT_OUTPUT_FILE"

# Projdi všechny soubory v rootu a přidej je do root.txt
for file in "$ROOT_DIR"/*; do
    if [ -f "$file" ] && [ "$file" != "$ROOT_OUTPUT_FILE" ] && [[ ! "$file" == *.txt ]]; then
        echo "File: $(basename "$file")" >> "$ROOT_OUTPUT_FILE"
        echo "Path: $file" >> "$ROOT_OUTPUT_FILE"
        echo "" >> "$ROOT_OUTPUT_FILE"
        cat "$file" >> "$ROOT_OUTPUT_FILE"
        echo "" >> "$ROOT_OUTPUT_FILE"
    fi
done

# Projdi všechny podadresáře
for dir in "$ROOT_DIR"/*/; do
    if [ -d "$dir" ]; then
        DIR_NAME=$(basename "$dir")
        OUTPUT_FILE="$ROOT_DIR/$DIR_NAME.txt"

        # Vyčisti výstupní soubor, pokud již existuje
        > "$OUTPUT_FILE"

        # Projdi všechny soubory v podadresáři
        for subfile in "$dir"*; do
            if [ -f "$subfile" ] && [[ ! "$subfile" == *.txt ]]; then
                echo "File: $(basename "$subfile")" >> "$OUTPUT_FILE"
                echo "Path: $subfile" >> "$OUTPUT_FILE"
                echo "" >> "$OUTPUT_FILE"
                cat "$subfile" >> "$OUTPUT_FILE"
                echo "" >> "$OUTPUT_FILE"
            fi
        done
    fi
done