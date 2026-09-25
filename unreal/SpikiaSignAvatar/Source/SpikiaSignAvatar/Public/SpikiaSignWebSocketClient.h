// Componente que se agrega al Actor del avatar MetaHuman (o a un Actor "manager" separado
// que referencie al MetaHuman). Mantiene la conexion WebSocket persistente con el
// orquestador Node.js (sign-avatar-orchestrator) y dispara la animacion de la seña
// correspondiente en el AnimInstance del esqueleto cada vez que llega un frame nuevo.
//
// Uso tipico en Blueprint/C++:
//   1. Agregar este componente al Actor del MetaHuman (o a un Actor dedicado que tenga una
//      referencia al SkeletalMeshComponent del MetaHuman en TargetSkeletalMeshComponent).
//   2. Configurar ServerUrl, SessionId y AuthToken (deben coincidir con lo que espera
//      sign-avatar-orchestrator - ver su .env.example: UNREAL_AUTH_TOKEN).
//   3. El AnimBlueprint del MetaHuman debe tener un nodo "Slot" llamado igual que
//      AnimationSlotName (default "SignLanguage") en algun lugar de su AnimGraph - ahi es
//      donde PlaySlotAnimationAsDynamicMontage inyecta la animacion sin pisar el resto del
//      arbol de animacion (locomotion, idle, etc.).

#pragma once

#include "CoreMinimal.h"
#include "Components/ActorComponent.h"
#include "IWebSocket.h"
#include "SpikiaSignWebSocketClient.generated.h"

class USkeletalMeshComponent;
class UAnimSequenceBase;

DECLARE_DYNAMIC_MULTICAST_DELEGATE_OneParam(FSpikiaSignFrameReceived, const FString&, Gloss);
DECLARE_DYNAMIC_MULTICAST_DELEGATE(FSpikiaSignConnectionChanged);

UCLASS(ClassGroup = (Spikia), meta = (BlueprintSpawnableComponent), Blueprintable)
class SPIKIASIGNAVATAR_API USpikiaSignWebSocketClient : public UActorComponent
{
	GENERATED_BODY()

public:
	USpikiaSignWebSocketClient();

	// --- Configuracion (editable en el Details panel del Blueprint) ---

	// URL del orquestador Node.js. En desarrollo local, "ws://127.0.0.1:4100/unreal".
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	FString ServerUrl = TEXT("ws://127.0.0.1:4100/unreal");

	// Slug de la sesion de Spikia que este avatar esta representando. El orquestador usa este
	// mismo valor para enrutar las secuencias que Laravel publica en
	// POST /api/sessions/{SessionId}/sign-sequence.
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	FString SessionId;

	// Debe coincidir con UNREAL_AUTH_TOKEN del .env del orquestador. Vacio = sin autenticar
	// (solo para desarrollo local).
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	FString AuthToken;

	// Nombre del Slot de animacion en el AnimGraph del MetaHuman donde se reproducen las
	// señas. Debe existir un nodo "Slot 'SignLanguage'" en el AnimBlueprint.
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	FName AnimationSlotName = TEXT("SignLanguage");

	// Carpeta del Content Browser donde viven los assets de animacion de cada glosa. El
	// nombre completo del asset a cargar es AnimationAssetBasePath + "/" + el campo
	// "animation" que manda el orquestador (ej. "/Game/Animations/Signs/Sign_Hola").
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	FString AnimationAssetBasePath = TEXT("/Game/Animations/Signs");

	// Blend in/out entre señas consecutivas - montages cortos como estos se ven mejor con
	// una transicion breve que con un corte seco.
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	float BlendInSeconds = 0.15f;

	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	float BlendOutSeconds = 0.15f;

	// Si es true, se conecta solo al arrancar el juego (BeginPlay). Si es false, hay que
	// llamar a Connect() manualmente (util si la sesion todavia no se eligio en ese momento).
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	bool bAutoConnect = true;

	// Reintento automatico si la conexion se cae (red, reinicio del orquestador, etc.).
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	float ReconnectDelaySeconds = 3.0f;

	// Referencia explicita al SkeletalMeshComponent del MetaHuman. Si se deja vacio, el
	// componente intenta resolverlo solo buscando el primer SkeletalMeshComponent del Owner.
	UPROPERTY(EditAnywhere, BlueprintReadWrite, Category = "Spikia|Sign Avatar")
	TObjectPtr<USkeletalMeshComponent> TargetSkeletalMeshComponent;

	// --- Delegates para Blueprint (ej. animar un indicador de "traduciendo" en UI) ---

	UPROPERTY(BlueprintAssignable, Category = "Spikia|Sign Avatar")
	FSpikiaSignFrameReceived OnSignFrameReceived;

	UPROPERTY(BlueprintAssignable, Category = "Spikia|Sign Avatar")
	FSpikiaSignConnectionChanged OnConnectedToOrchestrator;

	UPROPERTY(BlueprintAssignable, Category = "Spikia|Sign Avatar")
	FSpikiaSignConnectionChanged OnDisconnectedFromOrchestrator;

	// --- API publica ---

	UFUNCTION(BlueprintCallable, Category = "Spikia|Sign Avatar")
	void Connect();

	UFUNCTION(BlueprintCallable, Category = "Spikia|Sign Avatar")
	void Disconnect();

	UFUNCTION(BlueprintPure, Category = "Spikia|Sign Avatar")
	bool IsConnected() const;

protected:
	virtual void BeginPlay() override;
	virtual void EndPlay(const EEndPlayReason::Type EndPlayReason) override;

private:
	TSharedPtr<IWebSocket> WebSocket;
	FTimerHandle ReconnectTimerHandle;
	bool bShuttingDown = false;

	void HandleConnected();
	void HandleConnectionError(const FString& Error);
	void HandleClosed(int32 StatusCode, const FString& Reason, bool bWasClean);
	void HandleMessage(const FString& MessageJson);

	void ScheduleReconnect();
	void SendRegisterMessage();

	// Resuelve el AnimInstance del MetaHuman en este frame (no se cachea: el AnimInstance
	// puede recrearse si el AnimBlueprint se recompila en editor, o si el SkeletalMesh se
	// reasigna en runtime).
	class UAnimInstance* ResolveAnimInstance() const;

	void PlaySignAnimation(const FString& AnimationAssetName, float BlendInOverride = -1.f, float BlendOutOverride = -1.f);
};
