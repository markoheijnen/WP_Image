<?php
/**
 * WordPress Image Class for using image data and creating new image sizes
 *
 * @since 3.7.0
 * @package WordPress
 */
class WP_Image {
	private $filepath;
	private $attachment_id;

	private $editor;

	private $metadata;

	/**
	 * Each instance handles a single attachment.
	 */
	public function __construct( $attachment_id ) {
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$filepath = get_attached_file( $attachment_id );
			$size     = 'full';

			if ( $filepath && file_exists( $filepath ) ) {
				if ( 'full' != $size && ( $data = image_get_intermediate_size( $attachment_id, $size ) ) ) {
					$filepath = apply_filters( 'load_image_to_edit_filesystempath', path_join( dirname( $filepath ), $data['file'] ), $attachment_id, $size );
				}

				$this->filepath      = apply_filters( 'load_image_to_edit_path', $filepath, $attachment_id, 'full' );
				$this->attachment_id = $attachment_id;
			}
		}
	}

	/**
	 * Creates a new image size for an attachment
	 *
	 * @since 3.7.0
	 * @access public
	 *
	 * @param int $max_w
	 * @param int $max_h
	 * @param boolean $crop
	 * @param boolean $force
	 * @return boolean|WP_Error
	 */
	public function add_image_size( $name, $max_w, $max_h, $crop = false, $force = false ) {
		if ( has_image_size( $name ) ) {
			return new WP_Error( 'image_size_exists', __( 'This image size has been registered' ) );
		}

		$editor = $this->get_editor();
		$this->get_metadata();

		if ( $force == false && isset( $this->metadata['sizes'][ $name ] ) ) {
			return new WP_Error( 'image_exists', __( 'This image size already exists' ) );
		}

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->resize( $max_w, $max_h, $crop );
		$resized = $editor->save();

		return $this->store_image( $name, $resized );
	}

	/**
	 * Saves the new data of an image size to the metadata.
	 *
	 * @since 3.7.0
	 * @access public
	 *
	 * @param array $resized The array you get back from WP_Image_Editor:save()
	 * @return boolean
	 */
	public function store_image( $name, $resized ) {
		if ( ! is_wp_error( $resized ) && $resized ) {
			unset( $resized['path'] );
			$this->metadata['sizes'][ $name ] = $resized;

			return $this->update_metadata();
		}

		return false;
	}

	/**
	 * Gets an WP_Image_Editor for current attachment
	 *
	 * @since 3.7.0
	 * @access public
	 *
	 * @return WP_Image_Editor
	 */
	public function get_editor() {
		if ( ! isset( $this->editor ) ) {
			$this->editor = wp_get_image_editor( $this->filepath );
		}

		return $this->editor;
	}

	/**
	 * Gets the attachment meta data
	 *
	 * @since 3.7.0
	 * @access public
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! isset( $this->metadata ) ) {
			$this->metadata = wp_get_attachment_metadata( $this->attachment_id );
		}

		return $this->metadata; 
	}

	/**
	 * Updates attachment metadata if it's set
	 *
	 * @since 3.7.0
	 * @access public
	 *
	 * @return boolean
	 */
	public function update_metadata() {
		if ( $this->metadata ) {
			return wp_update_attachment_metadata( $this->attachment_id, $this->metadata );
		}

		return false;
	}


	public function image_metadata() {
		list( , , $sourceImageType ) = getimagesize( $this->filepath );

		$meta = array(
			'aperture' => 0,
			'credit' => '',
			'camera' => '',
			'caption' => '',
			'created_timestamp' => 0,
			'copyright' => '',
			'focal_length' => 0,
			'iso' => 0,
			'shutter_speed' => 0,
			'title' => '',
		);

		$meta = array_merge( $meta, $this->iptc(), $this->exif() );

		foreach ( array( 'title', 'caption', 'credit', 'copyright', 'camera', 'iso' ) as $key ) {
			if ( $meta[ $key ] && ! seems_utf8( $meta[ $key ] ) ) {
				$meta[ $key ] = utf8_encode( $meta[ $key ] );
			}
		}

		return apply_filters( 'wp_read_image_metadata', $meta, $this->filepath, $sourceImageType );
	}

	private function iptc() {
		$meta = array();

		// read iptc first, since it might contain data not available in exif such
		// as caption, description etc
		if ( is_callable( 'iptcparse' ) ) {
			getimagesize( $this->filepath, $info );

			if ( ! empty( $info['APP13'] ) ) {
				$iptc = iptcparse( $info['APP13'] );

				// headline, "A brief synopsis of the caption."
				if ( ! empty( $iptc['2#105'][0] ) ) {
					$meta['title'] = trim( $iptc['2#105'][0] );
				}
				// title, "Many use the Title field to store the filename of the image, though the field may be used in many ways."
				elseif ( ! empty( $iptc['2#005'][0] ) ) {
					$meta['title'] = trim( $iptc['2#005'][0] );
				}

				if ( ! empty( $iptc['2#120'][0] ) ) { // description / legacy caption
					$caption = trim( $iptc['2#120'][0] );
					if ( empty( $meta['title'] ) ) {
						// Assume the title is stored in 2:120 if it's short.
						if ( strlen( $caption ) < 80 ) {
							$meta['title'] = $caption;
						}
						else {
							$meta['caption'] = $caption;
						}
					} elseif ( $caption != $meta['title'] ) {
						$meta['caption'] = $caption;
					}
				}

				if ( ! empty( $iptc['2#110'][0] ) ) { // credit
					$meta['credit'] = trim( $iptc['2#110'][0] );
				}
				elseif ( ! empty( $iptc['2#080'][0] ) ) { // creator / legacy byline
					$meta['credit'] = trim( $iptc['2#080'][0] );
				}

				if ( ! empty( $iptc['2#055'][0] ) and ! empty( $iptc['2#060'][0] ) ) { // created date and time
					$meta['created_timestamp'] = strtotime( $iptc['2#055'][0] . ' ' . $iptc['2#060'][0] );
				}

				if ( ! empty( $iptc['2#116'][0] ) ) { // copyright
					$meta['copyright'] = trim( $iptc['2#116'][0] );
				}
			 }
		}

		return $meta;
	}

	private function exif() {
		$meta = array();

		list( , , $sourceImageType ) = getimagesize( $this->filepath );

		// fetch additional info from exif if available
		if ( is_callable( 'exif_read_data' ) && in_array( $sourceImageType, apply_filters( 'wp_read_image_metadata_types', array( IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM ) ) ) ) {
			$exif = @exif_read_data( $this->filepath );

			if ( !empty( $exif['Title'] ) ) {
				$meta['title'] = trim( $exif['Title'] );
			}

			if ( ! empty( $exif['ImageDescription'] ) ) {
				if ( empty( $meta['title'] ) && strlen( $exif['ImageDescription'] ) < 80 ) {
					// Assume the title is stored in ImageDescription
					$meta['title'] = trim( $exif['ImageDescription'] );
					if ( ! empty( $exif['COMPUTED']['UserComment'] ) && trim( $exif['COMPUTED']['UserComment'] ) != $meta['title'] ) {
						$meta['caption'] = trim( $exif['COMPUTED']['UserComment'] );
					}
				} elseif ( trim( $exif['ImageDescription'] ) != $meta['title'] ) {
					$meta['caption'] = trim( $exif['ImageDescription'] );
				}
			} elseif ( ! empty( $exif['Comments'] ) && trim( $exif['Comments'] ) != $meta['title'] ) {
				$meta['caption'] = trim( $exif['Comments'] );
			}

			if ( ! empty( $exif['Artist'] ) ) {
				$meta['credit'] = trim( $exif['Artist'] );
			}
			elseif ( ! empty($exif['Author'] ) ) {
				$meta['credit'] = trim( $exif['Author'] );
			}

			if ( ! empty( $exif['Copyright'] ) ) {
				$meta['copyright'] = trim( $exif['Copyright'] );
			}
			if ( ! empty($exif['FNumber'] ) ) {
				$meta['aperture'] = round( wp_exif_frac2dec( $exif['FNumber'] ), 2 );
			}
			if ( ! empty($exif['Model'] ) ) {
				$meta['camera'] = trim( $exif['Model'] );
			}
			if ( ! empty($exif['DateTimeDigitized'] ) ) {
				$meta['created_timestamp'] = wp_exif_date2ts($exif['DateTimeDigitized'] );
			}
			if ( ! empty($exif['FocalLength'] ) ) {
				$meta['focal_length'] = (string) wp_exif_frac2dec( $exif['FocalLength'] );
			}
			if ( ! empty($exif['ISOSpeedRatings'] ) ) {
				$meta['iso'] = is_array( $exif['ISOSpeedRatings'] ) ? reset( $exif['ISOSpeedRatings'] ) : $exif['ISOSpeedRatings'];
				$meta['iso'] = trim( $meta['iso'] );
			}
			if ( ! empty($exif['ExposureTime'] ) ) {
				$meta['shutter_speed'] = (string) wp_exif_frac2dec( $exif['ExposureTime'] );
			}
		}

		return $meta;
	}

}
