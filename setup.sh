#!/usr/bin/env bash
# Passiflore – script d'installation WordPress
# Usage : bash setup.sh [--wp-path <chemin>]
#
# Local by Flywheel : lancer depuis "Open Site Shell" (WP-CLI déjà dans le PATH).
# Serveur / autre env : WP-CLI doit être installé (https://wp-cli.org/).
# Windows : utiliser WSL ou Git Bash.

set -euo pipefail

# ─── Helpers ────────────────────────────────────────────────────────────────

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
step()  { echo -e "\n${GREEN}▶ $*${NC}"; }
warn()  { echo -e "${YELLOW}⚠  $*${NC}"; }
error() { echo -e "${RED}✗  $*${NC}" >&2; exit 1; }
ok()    { echo -e "   ${GREEN}✓${NC} $*"; }

# ─── Détection du chemin WordPress ──────────────────────────────────────────

if [[ "${1:-}" == "--wp-path" && -n "${2:-}" ]]; then
    WP_PATH="$2"
elif [[ -f "app/public/wp-config.php" ]]; then
    WP_PATH="app/public"   # Structure Local by Flywheel
elif [[ -f "wp-config.php" ]]; then
    WP_PATH="."            # Racine WordPress directe (serveur)
else
    error "WordPress introuvable. Lancez ce script depuis la racine du dépôt, ou passez --wp-path <chemin>."
fi

echo "WordPress détecté dans : ${WP_PATH}"

# ─── WP-CLI ─────────────────────────────────────────────────────────────────

if ! command -v wp &>/dev/null; then
    error "WP-CLI introuvable.
  • Local by Flywheel : utilisez \"Open Site Shell\" plutôt que le terminal système.
  • Serveur / autre env : installez WP-CLI → https://wp-cli.org/#installing"
fi

WP="wp --path=${WP_PATH}"
# Sur serveur lancé en root (ex. Docker), WP-CLI exige --allow-root
[[ "$(id -u 2>/dev/null)" == "0" ]] && WP="${WP} --allow-root"

# ─── Extensions ─────────────────────────────────────────────────────────────

step "Installation et activation des extensions…"

# Ordre important : WooCommerce en premier (woocommerce-payments en dépend)
PLUGINS=(
    woocommerce
    secure-custom-fields
    custom-post-type-ui
    seo-by-rank-math
    redirection
    wp-all-import
    updraftplus
    woocommerce-payments
)

for slug in "${PLUGINS[@]}"; do
    if $WP plugin is-installed "$slug" 2>/dev/null; then
        warn "${slug} déjà installé — ignoré"
    else
        echo "   Installation : ${slug}…"
        if $WP plugin install "$slug" --activate 2>&1; then
            ok "${slug}"
        else
            warn "${slug} introuvable sur WordPress.org — installez-le manuellement."
        fi
    fi
done

# ─── Thème parent Kadence ────────────────────────────────────────────────────

step "Installation du thème Kadence (parent)…"

if $WP theme is-installed kadence 2>/dev/null; then
    warn "Kadence déjà installé — ignoré"
else
    $WP theme install kadence
    ok "kadence"
fi

# ─── Activation du thème enfant ─────────────────────────────────────────────

step "Activation de kadence-child…"
$WP theme activate kadence-child
ok "kadence-child actif"

# ─── Permaliens ─────────────────────────────────────────────────────────────

step "Régénération des permaliens…"
$WP rewrite flush --hard
ok "permaliens régénérés"

# ─── Résumé ─────────────────────────────────────────────────────────────────

echo ""
echo -e "${GREEN}✓ Installation terminée.${NC}"
echo ""
echo "Étape suivante — importez la base de données :"
echo "  • Local by Flywheel : onglet Database → Import"
echo "  • Ligne de commande  : ${WP} db import local.sql"
echo ""
echo "Vérifiez ensuite CPT UI → Taxonomies pour vous assurer"
echo "que la taxonomie 'auteur' est bien enregistrée."
