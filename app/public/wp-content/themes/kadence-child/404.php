<?php
/**
 * The template for displaying 404 pages (Not Found)
 */

get_header();
?>

<div class="pf-404-container">
	<div class="pf-404-content">
		<h1 class="pf-404-title"><?php esc_html_e( 'Oups, vous avez ouvert une page qui n’existe pas !', 'kadence' ); ?></h1>

		<div class="pf-404-message">
			<p><?php
				printf(
					wp_kses_post( __( 'Peut-être que vous en trouverez une qui vous conviendra mieux dans un de nos ouvrages de <a href="%1$s">notre catalogue</a>.<br><br>Si vous pensez que c’est une erreur, n’hésitez pas à <a href="%2$s">nous contacter</a> pour nous le faire savoir, en vous remerciant d’avance.', 'kadence' ) ),
					esc_url( home_url( '/catalogue/' ) ),
					esc_url( home_url( '/contact/' ) )
				);
			?></p>
		</div>

		<div class="pf-404-actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pf-404-btn pf-404-btn-primary"><?php esc_html_e( 'Retour à l\'accueil', 'kadence' ); ?></a>
			<a href="<?php echo esc_url( home_url( '/catalogue' ) ); ?>" class="pf-404-btn pf-404-btn-secondary"><?php esc_html_e( 'Voir le catalogue', 'kadence' ); ?></a>
		</div>
	</div>
</div>

<style>
	.pf-404-container {
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 4rem 2rem;
		background: linear-gradient( 135deg, var( --pf-surface-alt, #fcfbf7 ) 0%, var( --pf-cream, #faf6f0 ) 100% );
	}

	.pf-404-content {
		max-width: 820px;
		text-align: center;
	}

	.pf-404-title {
		font-size: 2.5rem;
		font-weight: 700;
		color: var( --pf-heading, #1a1615 );
		margin-bottom: 2rem;
		line-height: 1.2;
	}

	.pf-404-message {
		font-size: 1.1rem;
		color: var( --pf-text, #5e524d );
		line-height: 1.8;
		margin-bottom: 3rem;
	}

	.pf-404-actions {
		display: flex;
		gap: 1.5rem;
		justify-content: center;
		flex-wrap: wrap;
	}

	.pf-404-btn {
		display: inline-block;
		padding: 0.875rem 2rem;
		border-radius: 4px;
		font-weight: 600;
		text-decoration: none;
		transition: all 0.3s ease;
		font-size: 1rem;
	}

	.pf-404-btn-primary {
		background-color: var( --pf-accent, #c62836 );
		color: white;
	}

	.pf-404-btn-primary:hover {
		background-color: var( --pf-accent-dark, #a0212c );
		transform: translateY( -2px );
		box-shadow: 0 4px 12px rgba( 198, 40, 54, 0.3 );
	}

	.pf-404-btn-secondary {
		background-color: transparent;
		color: var( --pf-accent, #c62836 );
		border: 2px solid var( --pf-accent, #c62836 );
	}

	.pf-404-btn-secondary:hover {
		background-color: var( --pf-accent, #c62836 );
		color: white;
	}

	@media ( max-width: 600px ) {
		.pf-404-container {
			padding: 2rem 1rem;
		}

		.pf-404-title {
			font-size: 1.75rem;
		}

		.pf-404-message {
			font-size: 1rem;
		}

		.pf-404-actions {
			flex-direction: column;
		}

		.pf-404-btn {
			width: 100%;
		}
	}
</style>

<?php
get_footer();
