import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	TextControl,
	Notice,
	ExternalLink,
} from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const {
		heading,
		description,
		submitLabel,
		successMessage,
		placeholder,
		allowPublicNote,
		allowPassword,
		expiresIn,
	} = attributes;

	const blockProps = useBlockProps( { className: 'scrt-link-wp-request' } );
	const configured = window.scrtLinkWp?.configured;
	const settingsUrl = window.scrtLinkWp?.settingsUrl || '';

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Form behavior', 'scrt-link-wp' ) } initialOpen>
					<ToggleControl
						label={ __( 'Allow an unencrypted "from" note', 'scrt-link-wp' ) }
						help={ __( 'A short plain-text note so you know who sent the secret. Not encrypted.', 'scrt-link-wp' ) }
						checked={ allowPublicNote }
						onChange={ ( v ) => setAttributes( { allowPublicNote: v } ) }
					/>
					<ToggleControl
						label={ __( 'Allow sender-set password', 'scrt-link-wp' ) }
						help={ __( 'Require a password to open the self-destructing link. Sender sets it.', 'scrt-link-wp' ) }
						checked={ allowPassword }
						onChange={ ( v ) => setAttributes( { allowPassword: v } ) }
					/>
					<TextControl
						type="number"
						label={ __( 'Expiration (milliseconds, 0 = site default)', 'scrt-link-wp' ) }
						value={ String( expiresIn ?? 0 ) }
						onChange={ ( v ) => setAttributes( { expiresIn: Math.max( 0, parseInt( v || '0', 10 ) || 0 ) } ) }
					/>
					<TextControl
						label={ __( 'Textarea placeholder', 'scrt-link-wp' ) }
						value={ placeholder }
						onChange={ ( v ) => setAttributes( { placeholder: v } ) }
					/>
					<TextControl
						label={ __( 'Submit button label', 'scrt-link-wp' ) }
						value={ submitLabel }
						onChange={ ( v ) => setAttributes( { submitLabel: v } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ ! configured && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'scrt.link plugin is not configured yet.', 'scrt-link-wp' ) }{ ' ' }
						<ExternalLink href={ settingsUrl }>
							{ __( 'Add your API key in Settings → scrt.link.', 'scrt-link-wp' ) }
						</ExternalLink>
					</Notice>
				) }

				<RichText
					tagName="h2"
					className="scrt-link-wp-request__heading"
					value={ heading }
					onChange={ ( v ) => setAttributes( { heading: v } ) }
					placeholder={ __( 'Heading…', 'scrt-link-wp' ) }
				/>
				<RichText
					tagName="p"
					className="scrt-link-wp-request__description"
					value={ description }
					onChange={ ( v ) => setAttributes( { description: v } ) }
					placeholder={ __( 'Short description shown above the form…', 'scrt-link-wp' ) }
				/>

				<textarea
					className="scrt-link-wp-request__textarea"
					placeholder={ placeholder }
					rows={ 6 }
					disabled
				/>

				{ allowPublicNote && (
					<input
						className="scrt-link-wp-request__note"
						type="text"
						placeholder={ __( 'From (optional, sent in plain text)', 'scrt-link-wp' ) }
						disabled
					/>
				) }

				{ allowPassword && (
					<input
						className="scrt-link-wp-request__password"
						type="password"
						placeholder={ __( 'Optional password', 'scrt-link-wp' ) }
						disabled
					/>
				) }

				<button type="button" className="scrt-link-wp-request__submit" disabled>
					{ submitLabel }
				</button>

				<p className="scrt-link-wp-request__success-preview">
					<em>{ __( 'Success message preview:', 'scrt-link-wp' ) }</em>{ ' ' }
					<RichText
						tagName="span"
						value={ successMessage }
						onChange={ ( v ) => setAttributes( { successMessage: v } ) }
					/>
				</p>
			</div>
		</>
	);
}
