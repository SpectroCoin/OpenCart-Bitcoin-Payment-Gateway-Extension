document.addEventListener("DOMContentLoaded", function () {
  // Get the form and the input fields
  var form = document.getElementById("form-spectrocoin");
  var merchantInput = document.querySelector(
    'input[name="payment_spectrocoin_merchant"]'
  );
  var projectInput = document.querySelector(
    'input[name="payment_spectrocoin_project"]'
  );
  var privateKeyTextarea = document.querySelector(
    'textarea[name="payment_spectrocoin_private_key"]'
  );
  var submitButton = document.querySelector('button[type="submit"]');

  // Add an event listener for input changes on the relevant fields
  [merchantInput, projectInput, privateKeyTextarea].forEach(function (input) {
    input.addEventListener("input", function () {
      // Check if any of the fields are empty
      var isEmpty =
        merchantInput.value.trim() === "" ||
        projectInput.value.trim() === "" ||
        privateKeyTextarea.value.trim() === "";
      // Disable the submit button if any field is empty, enable it otherwise
      submitButton.disabled = isEmpty;
    });
  });

  // Disable the submit button initially if any of the fields are empty
  if (
    merchantInput.value.trim() === "" ||
    projectInput.value.trim() === "" ||
    privateKeyTextarea.value.trim() === ""
  ) {
    submitButton.disabled = true;
  }
});
