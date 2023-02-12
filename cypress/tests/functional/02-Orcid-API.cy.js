/**
 * @file cypress/tests/functional/02-Orcid-API.cy.js
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 */

describe("Authorization Redirect: " + Cypress.env('orcid_url'), function () {

	var redirectUrl = "http://localhost/ojs/index.php/publicknowledge/orcidapi/orcidAuthorize?targetOp=profile";
	var orcidUrl = Cypress.env('orcid_url') + "/signin?oauth&client_id=" + Cypress.env('orcid_clientId')
		+ "&response_type=code&scope=/activities/update&redirect_uri=" + redirectUrl;


	it('Check ' + Cypress.env('orcid_url'), () => {
		cy.request(orcidUrl)
			.should((response) => {
				expect(response.status).to.eq(200)
			})
	});


});

