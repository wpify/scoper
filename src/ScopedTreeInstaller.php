<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\IO\IOInterface;
use RuntimeException;

/**
 * Patches the scoped vendor tree and swaps it into place.
 *
 * This used to be scripts/postinstall.php, a PHP file whose `%%placeholder%%` tokens were
 * substituted before it was written into the temp directory and executed as a subprocess.
 */
final class ScopedTreeInstaller {

	public function __construct(
		private readonly Configuration $config,
		private readonly IOInterface $io,
	) {
	}

	/**
	 * @param string $destination The php-scoper output directory.
	 */
	public function install( string $destination ): void {
		$this->fixAutoloadStatic( $destination );
		$this->neutraliseExposedSymbols( $destination );
		$this->publishLock( $destination );
		$this->swapDepsFolder( $destination );
	}

	/**
	 * Namespaces the keys of the `files` autoload array.
	 *
	 * Composer dedupes those files through $GLOBALS['__composer_autoload_files'], keyed by an
	 * md5 that depends only on the package name and the file path - so the scoped copy of a
	 * package produces the very same key as the unscoped one, and whichever autoloader runs
	 * second silently skips its own file. Prefixing the keys keeps the two vendors apart.
	 */
	private function fixAutoloadStatic( string $destination ): void {
		$path = $this->path( $destination, 'vendor', 'composer', 'autoload_static.php' );

		// The key has to stay a plain identifier, so everything that is not alphanumeric goes.
		$keyPrefix = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]/', '', $this->config->prefix ) );

		$contents = $this->read( $path );

		// Only inside the $files array: the same key pattern also matches a $classMap entry for a
		// single-segment global class name (`'Requests' => ...`), and prefixing that breaks the map.
		//
		// `?? $matches[0]` / `?? $contents` because preg_replace() returns null when PCRE gives up,
		// and writing that as a string would truncate the autoloader of the whole scoped tree to
		// nothing rather than leave a key unprefixed.
		$this->write( $path, preg_replace_callback(
			'/public\s+static\s+\$files\s*=\s*array\s*\(.*?\n\s*\);/s',
			static function ( array $matches ) use ( $keyPrefix ): string {
				return preg_replace( "/'([[:alnum:]]+)'(\s*=>)/", "'" . $keyPrefix . "\$1'\$2", $matches[0] ) ?? $matches[0];
			},
			$contents,
			1
		) ?? $contents );
	}

	/**
	 * Comments out the exposed classes and functions - nothing is meant to be exposed.
	 */
	private function neutraliseExposedSymbols( string $destination ): void {
		$path     = $this->path( $destination, 'vendor', 'scoper-autoload.php' );
		$autoload = $this->read( $path );

		// `?? $autoload` because preg_replace() returns null when PCRE gives up, and an empty
		// scoper-autoload.php takes the whole scoped tree down with it.
		$autoload = preg_replace( '/^humbug_phpscoper_expose_.*;$/m', '// $0 // commented by WPify Scoper', $autoload ) ?? $autoload;
		$autoload = preg_replace( '/^if \(!function_exists\(.*}$/m', '// $0 // commented by WPify Scoper', $autoload ) ?? $autoload;

		$this->write( $path, $autoload );
	}

	private function publishLock( string $destination ): void {
		$lock = $this->path( $destination, 'composer.lock' );

		// Checked before the existing lock is touched: removing it first and only then finding out
		// there is nothing to put in its place loses the file the project committed.
		if ( ! is_file( $lock ) ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: the scoped run produced no %s, keeping %s as it is.',
				$lock,
				$this->config->composerLock
			) );
		}

		// remove() rather than letting copy() overwrite: the destination may be a symlink, and
		// writing through it would clobber whatever it points at.
		$this->remove( $this->config->composerLock );

		if ( ! @copy( $lock, $this->config->composerLock ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot write %s.', $this->config->composerLock ) );
		}
	}

	/**
	 * Swaps the deps folder in place: the old tree is only destroyed once the new one is there.
	 */
	private function swapDepsFolder( string $destination ): void {
		$scoped = $this->path( $destination, 'vendor' );
		$deps   = $this->config->folder;

		if ( ! is_dir( $scoped ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: scoped vendor not found at %s.', $scoped ) );
		}

		// The backup lives inside the temp folder on purpose: $deps is often a path inside vendor/,
		// where a sibling .bak would be picked up by release builds and CI artifacts.
		$backup    = $this->path( $this->config->tempDir, 'deps-backup-' . getmypid() );
		$hasBackup = false;

		if ( file_exists( $deps ) || is_link( $deps ) ) {
			if ( ! $this->moveTree( $deps, $backup ) ) {
				throw new RuntimeException( sprintf( 'wpify-scoper: cannot move the existing %s aside.', $deps ) );
			}

			$hasBackup = true;
		}

		if ( ! $this->moveTree( $scoped, $deps ) ) {
			if ( $hasBackup && ! $this->moveTree( $backup, $deps ) ) {
				throw new RuntimeException( sprintf(
					'wpify-scoper: cannot move the scoped dependencies into %s and the previous ones could not be restored, they are kept in %s.',
					$deps,
					$backup
				) );
			}

			throw new RuntimeException( sprintf(
				'wpify-scoper: cannot move the scoped dependencies into %s, the previous ones were restored.',
				$deps
			) );
		}

		if ( $hasBackup && ! $this->remove( $backup ) ) {
			$this->warn( sprintf( 'cannot remove the backup of the previous dependencies in %s', $backup ) );
		}
	}

	/**
	 * Moves a tree, falling back to copy + verify + delete when rename() cannot do it
	 * (different filesystems report EXDEV, Windows fails on locked files).
	 *
	 * Success means the destination holds the tree. Failing to delete the source afterwards is a
	 * leftover, not a failed move: reporting it as one made the caller "restore" a backup on top of
	 * the tree it had just installed, which left the deps folder as a mix of both.
	 */
	private function moveTree( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) {
			return true;
		}

		if ( ! $this->copyTree( $from, $to ) || ! $this->verifyTree( $from, $to ) ) {
			$this->remove( $to );

			return false;
		}

		if ( ! $this->remove( $from ) ) {
			$this->warn( sprintf( 'cannot remove %s after copying it to %s', $from, $to ) );
		}

		return true;
	}

	/**
	 * Recursively copies a file, a directory or a symlink. Symlinks are recreated, never followed.
	 */
	private function copyTree( string $from, string $to ): bool {
		if ( is_link( $from ) ) {
			$target = readlink( $from );

			return false !== $target && @symlink( $target, $to );
		}

		if ( is_dir( $from ) ) {
			if ( ! is_dir( $to ) && ! @mkdir( $to, 0755, true ) && ! is_dir( $to ) ) {
				return false;
			}

			$dir = @opendir( $from );

			if ( false === $dir ) {
				return false;
			}

			$copied = true;

			while ( $copied && false !== ( $file = readdir( $dir ) ) ) {
				if ( '.' !== $file && '..' !== $file ) {
					$copied = $this->copyTree( $from . '/' . $file, $to . '/' . $file );
				}
			}

			closedir( $dir );

			return $copied;
		}

		return @copy( $from, $to );
	}

	/**
	 * Checks that every entry of $from exists in $to with the same type and size.
	 */
	private function verifyTree( string $from, string $to ): bool {
		if ( is_link( $from ) ) {
			return is_link( $to ) && readlink( $to ) === readlink( $from );
		}

		if ( is_dir( $from ) ) {
			if ( ! is_dir( $to ) ) {
				return false;
			}

			$dir = @opendir( $from );

			if ( false === $dir ) {
				return false;
			}

			$verified = true;

			while ( $verified && false !== ( $file = readdir( $dir ) ) ) {
				if ( '.' !== $file && '..' !== $file ) {
					$verified = $this->verifyTree( $from . '/' . $file, $to . '/' . $file );
				}
			}

			closedir( $dir );

			return $verified;
		}

		return is_file( $to ) && ! is_link( $to ) && filesize( $to ) === filesize( $from );
	}

	/**
	 * Removes a file, a directory or a symlink.
	 *
	 * Never follows symlinks: is_dir() and is_file() do, so the link has to be checked for first,
	 * otherwise the target of the link gets deleted instead.
	 */
	private function remove( string $src ): bool {
		if ( is_link( $src ) ) {
			// Remove the link itself, never what it points at. rmdir() covers Windows directory junctions.
			if ( ! @unlink( $src ) && ! @rmdir( $src ) ) {
				$this->warn( sprintf( 'cannot remove symlink %s', $src ) );

				return false;
			}

			return true;
		}

		if ( is_dir( $src ) ) {
			$dir = @opendir( $src );

			if ( false === $dir ) {
				$this->warn( sprintf( 'cannot read directory %s', $src ) );

				return false;
			}

			$removed = true;

			while ( false !== ( $file = readdir( $dir ) ) ) {
				if ( '.' !== $file && '..' !== $file ) {
					if ( ! $this->remove( $src . '/' . $file ) ) {
						$removed = false;
					}
				}
			}

			closedir( $dir );

			if ( ! $removed ) {
				return false;
			}

			if ( ! @rmdir( $src ) ) {
				$this->warn( sprintf( 'cannot remove directory %s', $src ) );

				return false;
			}

			return true;
		}

		if ( file_exists( $src ) && ! @unlink( $src ) ) {
			$this->warn( sprintf( 'cannot remove file %s', $src ) );

			return false;
		}

		return true;
	}

	private function path( string ...$parts ): string {
		return implode( DIRECTORY_SEPARATOR, $parts );
	}

	private function read( string $path ): string {
		$contents = @file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot read %s.', $path ) );
		}

		return $contents;
	}

	private function write( string $path, string $contents ): void {
		if ( false === @file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot write %s.', $path ) );
		}
	}

	private function warn( string $message ): void {
		$this->io->writeError( sprintf( '<warning>wpify-scoper: %s</warning>', $message ) );
	}
}
