$(document).ready(function () {
    $("#challenge-page").show();
    $("#challengeModal").modal('show');
});

window.onloadTurnstileCallback = function () {
    turnstile.render("#turnstile-container", {
        sitekey: "0x4AAAAAABf_IQUgByTNqHGM",
        callback: function (token) {
            const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 'token': token })
            };
            fetch('/challenge/verification', options)
                .then((response) => response.json())
                .then((data) => {
                    if (data.success) {
                        window.location.replace($('#turnstile-container').data('redirect'));
                    }
                    else {
                        $("#turnstile-container").css('opacity', '0').css('font-size', '1.5rem').addClass('alert alert-danger').text("We're sorry. Verification has failed. You can try again by refreshing the page. If the problem persists, please contact us at repository@fitnyc.edu").animate({ opacity: 1 });
                    }
                })
                .catch(console.error);
        },
    });
};